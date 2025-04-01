<?php
session_start(); // Start session

// Afficher les erreurs pour le débogage (à commenter en production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
    header("Location: expired");  // Redirige vers la page de connexion si non connecté
    exit();
}

// Database Connection
$host = 'localhost';
$user = 'root';
$pass = 'root';
$dbname = 'cloudbox';

try {
    $conn = new mysqli($host, $user, $pass, $dbname);
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
} catch (Exception $e) {
    die("Connection error: " . $e->getMessage());
}

$username = $_SESSION['username'];
$userid = $_SESSION['user_id'];
$messages = [];

// Vérifier si la table 'folders' existe
$folderTableExists = false;
$result = $conn->query("SHOW TABLES LIKE 'folders'");
if ($result && $result->num_rows > 0) {
    $folderTableExists = true;
}

// Vérifier si la colonne folder_id existe dans la table files
$folderIdColumnExists = false;
if ($folderTableExists) {
    $result = $conn->query("SHOW COLUMNS FROM files LIKE 'folder_id'");
    if ($result && $result->num_rows > 0) {
        $folderIdColumnExists = true;
    } else {
        // Tenter d'ajouter la colonne si elle n'existe pas
        try {
            $conn->query("ALTER TABLE files ADD COLUMN folder_id INT DEFAULT NULL");
            $folderIdColumnExists = true;
            $messages[] = "<p class='info-message'>Added folder_id column to files table.</p>";
        } catch (Exception $e) {
            $messages[] = "<p class='error-message'>Error adding folder_id column: " . $e->getMessage() . "</p>";
        }
    }
}

// Récupérer l'ID du dossier courant (si spécifié dans l'URL)
$current_folder_id = isset($_GET['folder_id']) ? intval($_GET['folder_id']) : null;

// Créer un nouveau dossier - uniquement si la table folders existe
if ($folderTableExists && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_folder_name'])) {
    $folder_name = $conn->real_escape_string(trim($_POST['new_folder_name']));
    
    if (!empty($folder_name)) {
        try {
            // Vérifier si un dossier avec le même nom existe déjà au même niveau
            $checkStmt = $conn->prepare("SELECT id FROM folders WHERE user_id = ? AND folder_name = ? AND parent_folder_id " . ($current_folder_id ? "= ?" : "IS NULL"));
            
            if ($current_folder_id) {
                $checkStmt->bind_param("isi", $userid, $folder_name, $current_folder_id);
            } else {
                $checkStmt->bind_param("is", $userid, $folder_name);
            }
            
            $checkStmt->execute();
            $checkStmt->store_result();
            
            if ($checkStmt->num_rows > 0) {
                $messages[] = "<p id='folder-message' class='error-message'>A folder with this name already exists at this location.</p>";
            } else {
                // Insérer le nouveau dossier
                $stmt = $conn->prepare("INSERT INTO folders (user_id, folder_name, parent_folder_id) VALUES (?, ?, ?)");
                $stmt->bind_param("isi", $userid, $folder_name, $current_folder_id);
                
                if ($stmt->execute()) {
                    $messages[] = "<p id='folder-message' class='success-message'>Folder created successfully!</p>";
                } else {
                    throw new Exception($conn->error);
                }
            }
        } catch (Exception $e) {
            $messages[] = "<p id='folder-message' class='error-message'>Error creating folder: " . $e->getMessage() . "</p>";
        }
    } else {
        $messages[] = "<p id='folder-message' class='error-message'>Please enter a valid folder name.</p>";
    }
}

// Get user's quota information
try {
    $storage_quota = 104857600; // 100MB default
    $quotaStmt = $conn->prepare("SELECT storage_quota FROM users WHERE id = ?");
    if ($quotaStmt) {
        $quotaStmt->bind_param("i", $userid);
        $quotaStmt->execute();
        $quotaStmt->bind_result($quota);
        if ($quotaStmt->fetch() && $quota) {
            $storage_quota = $quota;
        }
        $quotaStmt->close();
    }
} catch (Exception $e) {
    // Continue with default quota
}

// Get user's current storage usage
try {
    $total_usage = 0;
    $usageStmt = $conn->prepare("SELECT SUM(file_size) as total_usage FROM files WHERE user_id = ?");
    if ($usageStmt) {
        $usageStmt->bind_param("i", $userid);
        $usageStmt->execute();
        $usageStmt->bind_result($usage);
        if ($usageStmt->fetch() && $usage) {
            $total_usage = $usage;
        }
        $usageStmt->close();
    }
} catch (Exception $e) {
    // Continue with default usage
}

// Calculate percentage
$usage_percentage = ($storage_quota > 0) ? ($total_usage / $storage_quota) * 100 : 0;

// Handle File Upload with Duplicate Check and Quota Check
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
    $filename = $_FILES['file']['name'];
    $file_tmp = $_FILES['file']['tmp_name'];
    $file_size = $_FILES['file']['size'];
    $file_type = $_FILES['file']['type'];

    // Check if upload would exceed quota
    if (($total_usage + $file_size) > $storage_quota) {
        $messages[] = "<p id='upload-message' class='error-message'>Storage quota exceeded. Your quota is " . 
            number_format($storage_quota / 1048576, 2) . "MB and you're using " . 
            number_format($total_usage / 1048576, 2) . "MB. Please delete some files or contact administrator.</p>";
    } else {
        try {
            // Check if file already exists for the user
            $checkQuery = "SELECT id FROM files WHERE user_id = ? AND filename = ?";
            $params = [$userid, $filename];
            $types = "is";
            
            // Add folder condition if folder_id column exists
            if ($folderIdColumnExists) {
                $checkQuery .= " AND folder_id " . ($current_folder_id ? "= ?" : "IS NULL");
                if ($current_folder_id) {
                    $params[] = $current_folder_id;
                    $types .= "i";
                }
            }
            
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->bind_param($types, ...$params);
            $checkStmt->execute();
            $checkStmt->store_result();

            if ($checkStmt->num_rows > 0) {
                $messages[] = "<p id='upload-message' class='error-message'>File already exists in this location.</p>";
            } else {
                // Read the file content
                $file_content = file_get_contents($file_tmp);

                // Prepare insert query based on whether folder_id column exists
                if ($folderIdColumnExists) {
                    $insertStmt = $conn->prepare("INSERT INTO files (user_id, filename, file_size, file_type, folder_id) VALUES (?, ?, ?, ?, ?)");
                    $insertStmt->bind_param("isiss", $userid, $filename, $file_size, $file_type, $current_folder_id);
                } else {
                    $insertStmt = $conn->prepare("INSERT INTO files (user_id, filename, file_size, file_type) VALUES (?, ?, ?, ?)");
                    $insertStmt->bind_param("isis", $userid, $filename, $file_size, $file_type);
                }
                
                if ($insertStmt->execute()) {
                    $file_id = $insertStmt->insert_id;

                    // Insert file content into the `file_content` table
                    $contentStmt = $conn->prepare("INSERT INTO file_content (file_id, content) VALUES (?, ?)");
                    $contentStmt->bind_param("is", $file_id, $file_content);
                    
                    if ($contentStmt->execute()) {
                        // Update total usage for display
                        $total_usage += $file_size;
                        $usage_percentage = ($total_usage / $storage_quota) * 100;
                        $messages[] = "<p id='upload-message' class='success-message'>File uploaded successfully.</p>";
                    } else {
                        throw new Exception("Error saving file content: " . $conn->error);
                    }
                } else {
                    throw new Exception("Error saving file metadata: " . $conn->error);
                }
            }
        } catch (Exception $e) {
            $messages[] = "<p id='upload-message' class='error-message'>" . $e->getMessage() . "</p>";
        }
    }
}

// Handle File Deletion
if (isset($_GET['delete_id']) && is_numeric($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    try {
        // Get file size before deletion to update usage display
        $sizeStmt = $conn->prepare("SELECT file_size FROM files WHERE id = ? AND user_id = ?");
        $sizeStmt->bind_param("ii", $delete_id, $userid);
        $sizeStmt->execute();
        $sizeStmt->bind_result($file_size);
        $sizeStmt->fetch();
        $sizeStmt->close();
        
        $deleteStmt = $conn->prepare("DELETE FROM files WHERE id = ? AND user_id = ?");
        $deleteStmt->bind_param("ii", $delete_id, $userid);
        if ($deleteStmt->execute()) {
            // Update total usage for display if file was deleted
            if ($file_size) {
                $total_usage -= $file_size;
                if ($total_usage < 0) $total_usage = 0;
                $usage_percentage = ($total_usage / $storage_quota) * 100;
            }
            $messages[] = "<p id='delete-message' class='success-message'>File deleted successfully.</p>";
        } else {
            throw new Exception("Error deleting file: " . $conn->error);
        }
    } catch (Exception $e) {
        $messages[] = "<p id='delete-message' class='error-message'>" . $e->getMessage() . "</p>";
    }
}

// Supprimer un dossier - uniquement si la table folders existe
if ($folderTableExists && isset($_GET['delete_folder']) && is_numeric($_GET['delete_folder'])) {
    $folder_id = intval($_GET['delete_folder']);
    
    try {
        // Vérifier que le dossier appartient à l'utilisateur
        $checkStmt = $conn->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ?");
        $checkStmt->bind_param("ii", $folder_id, $userid);
        $checkStmt->execute();
        $checkStmt->store_result();
        
        if ($checkStmt->num_rows > 0) {
            // Supprimer le dossier (les triggers de cascade supprimeront les fichiers et sous-dossiers)
            $deleteStmt = $conn->prepare("DELETE FROM folders WHERE id = ?");
            $deleteStmt->bind_param("i", $folder_id);
            
            if ($deleteStmt->execute()) {
                $messages[] = "<p id='delete-message' class='success-message'>Folder deleted successfully.</p>";
                
                // Si on vient de supprimer le dossier courant, rediriger vers le parent ou la racine
                if ($folder_id == $current_folder_id) {
                    $parent_query = $conn->prepare("SELECT parent_folder_id FROM folders WHERE id = ?");
                    $parent_query->bind_param("i", $current_folder_id);
                    $parent_query->execute();
                    $parent_query->bind_result($parent_id);
                    $parent_query->fetch();
                    $parent_query->close();
                    
                    if ($parent_id) {
                        header("Location: home.php?folder_id=" . $parent_id);
                    } else {
                        header("Location: home.php");
                    }
                    exit();
                }
            } else {
                throw new Exception("Error deleting folder: " . $conn->error);
            }
        } else {
            $messages[] = "<p id='delete-message' class='error-message'>You don't have permission to delete this folder.</p>";
        }
    } catch (Exception $e) {
        $messages[] = "<p id='delete-message' class='error-message'>" . $e->getMessage() . "</p>";
    }
}

// Obtenir le chemin du dossier actuel pour afficher une navigation en fil d'Ariane
function getFolderBreadcrumb($conn, $folder_id, $userid) {
    $breadcrumb = [];
    $current_id = $folder_id;
    
    try {
        while ($current_id) {
            $stmt = $conn->prepare("SELECT id, folder_name, parent_folder_id FROM folders WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $current_id, $userid);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $folder = $result->fetch_assoc();
                array_unshift($breadcrumb, ['id' => $folder['id'], 'name' => $folder['folder_name']]);
                $current_id = $folder['parent_folder_id'];
            } else {
                break;
            }
        }
    } catch (Exception $e) {
        // Return empty breadcrumb in case of error
    }
    
    return $breadcrumb;
}

// Initialiser les variables pour la navigation dans les dossiers
$current_folder_name = "My Drive";
$parent_folder_id = null;
$folders = [];
$breadcrumb = [];

// Récupérer les informations sur le dossier actuel - uniquement si la table folders existe
if ($folderTableExists && $current_folder_id) {
    try {
        $stmt = $conn->prepare("SELECT folder_name, parent_folder_id FROM folders WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $current_folder_id, $userid);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $folder = $result->fetch_assoc();
            $current_folder_name = $folder['folder_name'];
            $parent_folder_id = $folder['parent_folder_id'];
            
            // Générer le fil d'Ariane
            $breadcrumb = getFolderBreadcrumb($conn, $current_folder_id, $userid);
        } else {
            // Rediriger vers le dossier racine si le dossier n'existe pas ou n'appartient pas à l'utilisateur
            header("Location: home.php");
            exit();
        }
    } catch (Exception $e) {
        $messages[] = "<p class='error-message'>" . $e->getMessage() . "</p>";
    }
}

// Récupérer les sous-dossiers du dossier actuel - uniquement si la table folders existe
if ($folderTableExists) {
    try {
        $query = "SELECT id, folder_name, created_at FROM folders WHERE user_id = ? AND parent_folder_id ";
        $query .= $current_folder_id ? "= ? " : "IS NULL ";
        $query .= "ORDER BY folder_name";
        
        $stmt = $conn->prepare($query);
        
        if ($current_folder_id) {
            $stmt->bind_param("ii", $userid, $current_folder_id);
        } else {
            $stmt->bind_param("i", $userid);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $folders[] = $row;
        }
    } catch (Exception $e) {
        // Continue with empty folders array
    }
}

// Récupérer les fichiers du dossier actuel
$files = [];
try {
    $query = "SELECT id, filename, file_size, file_type, created_at FROM files WHERE user_id = ? ";
    
    // Ajouter la condition de dossier si la colonne existe
    if ($folderIdColumnExists) {
        $query .= "AND folder_id " . ($current_folder_id ? "= ? " : "IS NULL ");
    }
    
    $query .= "ORDER BY filename";
    
    $stmt = $conn->prepare($query);
    
    if ($folderIdColumnExists && $current_folder_id) {
        $stmt->bind_param("ii", $userid, $current_folder_id);
    } else {
        $stmt->bind_param("i", $userid);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $files[] = $row;
    }
} catch (Exception $e) {
    $messages[] = "<p class='error-message'>Error loading files: " . $e->getMessage() . "</p>";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CloudBOX - Dashboard</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Folder and File Management Styles */
        .folder-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            grid-gap: 20px;
            margin-bottom: 30px;
        }
        
        .folder-item, .file-item {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 15px;
            display: flex;
            flex-direction: column;
            align-items: center;
            transition: transform 0.2s, box-shadow 0.2s;
            position: relative;
        }
        
        .folder-item:hover, .file-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }
        
        .folder-icon {
            font-size: 48px;
            color: #4f46e5;
            margin-bottom: 10px;
        }
        
        .file-icon {
            font-size: 48px;
            color: #60a5fa;
            margin-bottom: 10px;
        }
        
        .folder-name, .file-name {
            font-weight: 500;
            text-align: center;
            width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .folder-actions, .file-actions {
            display: flex;
            justify-content: center;
            margin-top: 15px;
            width: 100%;
        }
        
        .action-btn {
            padding: 5px 10px;
            margin: 0 5px;
            background-color: #f3f4f6;
            color: #374151;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            transition: background-color 0.2s;
        }
        
        .action-btn:hover {
            background-color: #e5e7eb;
        }
        
        .action-btn.delete {
            color: #ef4444;
        }
        
        .action-btn.delete:hover {
            background-color: #fee2e2;
        }
        
        .breadcrumb {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding: 10px;
            background-color: #f3f4f6;
            border-radius: 8px;
            overflow-x: auto;
            white-space: nowrap;
        }
        
        .breadcrumb a {
            text-decoration: none;
            color: #4f46e5;
            margin: 0 5px;
        }
        
        .breadcrumb span {
            margin: 0 5px;
            color: #9ca3af;
        }
        
        .folder-actions-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .folder-form {
            display: flex;
            align-items: center;
        }
        
        .folder-form input[type="text"] {
            padding: 8px 12px;
            border-radius: 5px 0 0 5px;
            border: 1px solid #d1d5db;
            border-right: none;
        }
        
        .folder-form button {
            padding: 8px 15px;
            background-color: #4f46e5;
            color: white;
            border: none;
            border-radius: 0 5px 5px 0;
            cursor: pointer;
        }
        
        .file-details {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
            text-align: center;
        }
        
        .section-title {
            margin-top: 30px;
            margin-bottom: 15px;
            color: #1f2937;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
        }
        
        .section-title span {
            margin-right: 10px;
        }
        
        .upload-container {
            margin-top: 20px;
            background-color: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .success-message {
            background-color: #d1fae5;
            color: #047857;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .error-message {
            background-color: #fee2e2;
            color: #b91c1c;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .info-message {
            background-color: #dbeafe;
            color: #1e40af;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .storage-bar-container {
            width: 100%;
            background-color: #e5e7eb;
            border-radius: 9999px;
            height: 8px;
            margin: 0.75rem 0;
            overflow: hidden;
        }
        
        .storage-bar {
            height: 100%;
            border-radius: 9999px;
            transition: width 0.5s ease;
        }
        
        .storage-info {
            display: flex;
            justify-content: space-between;
            font-size: 0.875rem;
            color: #6b7280;
            margin-top: 0.5rem;
        }
        
        @media (max-width: 768px) {
            .folder-container {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }
            
            .folder-actions-container {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .folder-form {
                margin-top: 10px;
                width: 100%;
            }
            
            .folder-form input[type="text"] {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <div class="logo">
            <img src="logo.png" alt="CloudBOX Logo" height="40">
        </div>
        <h1>CloudBOX</h1>
        <div class="search-bar">
            <input type="text" placeholder="Search here...">
        </div>
    </div>
    
    <nav class="dashboard-nav">
        <a href="home">📊 Dashboard</a>
        <a href="drive">📁 My Drive</a>
        <?php if(isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1): ?>
        <a href="admin">👑 Admin Panel</a>
        <?php endif; ?>
        <a href="shared">🔄 Shared Files</a>
        <a href="monitoring">📈 Monitoring</a>
        <a href="#">🗑️ Trash</a>
        <a href="logout">🚪 Logout</a>
    </nav>

    <main>
        <h1>Welcome, <?= htmlspecialchars($username) ?>!</h1>
        
        <!-- Storage Usage -->
        <div class="admin-section">
            <h2>Storage Usage</h2>
            <div class="storage-bar-container">
                <div class="storage-bar" style="width: <?= min(100, $usage_percentage) ?>%; background-color: <?= $usage_percentage > 90 ? '#ef4444' : ($usage_percentage > 70 ? '#f59e0b' : '#22c55e') ?>;"></div>
            </div>
            <div class="storage-info">
                <span><?= number_format($total_usage / 1048576, 2) ?> MB used</span>
                <span><?= number_format($usage_percentage, 1) ?>%</span>
                <span><?= number_format($storage_quota / 1048576, 2) ?> MB total</span>
            </div>
        </div>
        
        <!-- Display any messages -->
        <?php foreach ($messages as $message): ?>
            <?= $message ?>
        <?php endforeach; ?>
        
        <?php if ($folderTableExists): ?>
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb">
            <a href="home.php">📁 Root</a>
            <?php foreach ($breadcrumb as $folder): ?>
                <span>›</span>
                <a href="home.php?folder_id=<?= $folder['id'] ?>"><?= htmlspecialchars($folder['name']) ?></a>
            <?php endforeach; ?>
        </div>
        
        <!-- Current Folder Actions -->
        <div class="folder-actions-container">
            <h2><?= htmlspecialchars($current_folder_name) ?></h2>
            <form class="folder-form" method="POST">
                <input type="text" name="new_folder_name" placeholder="New folder name" required>
                <button type="submit">Create Folder</button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- File Upload Form -->
        <div class="upload-container">
            <h3>Upload Files</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="file" name="file" required>
                <button type="submit">Upload</button>
            </form>
        </div>
        
        <?php if ($folderTableExists && !empty($folders)): ?>
        <!-- Folders Section -->
        <div class="section-title">
            <span>📁</span> Folders
        </div>
        <div class="folder-container">
            <?php foreach ($folders as $folder): ?>
                <div class="folder-item">
                    <div class="folder-icon">📁</div>
                    <div class="folder-name"><?= htmlspecialchars($folder['folder_name']) ?></div>
                    <div class="file-details">Created: <?= date('M d, Y', strtotime($folder['created_at'])) ?></div>
                    <div class="folder-actions">
                        <a href="home.php?folder_id=<?= $folder['id'] ?>" class="action-btn">Open</a>
                        <a href="home.php?delete_folder=<?= $folder['id'] ?><?= $current_folder_id ? '&folder_id='.$current_folder_id : '' ?>" class="action-btn delete" onclick="return confirm('Are you sure you want to delete this folder and all its contents?');">Delete</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Files Section -->
        <?php if (!empty($files)): ?>
            <div class="section-title">
                <span>📄</span> Files
            </div>
            <div class="folder-container">
                <?php foreach ($files as $file): ?>
                    <div class="file-item">
                        <?php
                        // Déterminer l'icône en fonction du type de fichier
                        $icon = '📄'; // Default document icon
                        if (strpos($file['file_type'], 'image/') === 0) {
                            $icon = '🖼️'; // Image
                        } elseif (strpos($file['file_type'], 'video/') === 0) {
                            $icon = '🎬'; // Video
                        } elseif (strpos($file['file_type'], 'audio/') === 0) {
                            $icon = '🎵'; // Audio
                        } elseif (strpos($file['file_type'], 'application/pdf') === 0) {
                            $icon = '$icon = '📕'; // PDF
                        } elseif (strpos($file['file_type'], 'application/zip') === 0 || strpos($file['file_type'], 'application/x-rar') === 0) {
                            $icon = '🗜️'; // Archive
                        } elseif (strpos($file['file_type'], 'application/msword') === 0 || strpos($file['file_type'], 'application/vnd.openxmlformats-officedocument.wordprocessingml') === 0) {
                            $icon = '📝'; // Word document
                        } elseif (strpos($file['file_type'], 'application/vnd.ms-excel') === 0 || strpos($file['file_type'], 'application/vnd.openxmlformats-officedocument.spreadsheetml') === 0) {
                            $icon = '📊'; // Excel
                        } elseif (strpos($file['file_type'], 'application/vnd.ms-powerpoint') === 0 || strpos($file['file_type'], 'application/vnd.openxmlformats-officedocument.presentationml') === 0) {
                            $icon = '📽️'; // PowerPoint
                        } elseif (strpos($file['file_type'], 'text/') === 0) {
                            $icon = '📋'; // Text
                        }
                        ?>
                        <div class="file-icon"><?= $icon ?></div>
                        <div class="file-name"><?= htmlspecialchars($file['filename']) ?></div>
                        <div class="file-details"><?= number_format($file['file_size'] / 1024, 2) ?> KB</div>
                        <div class="file-details">Created: <?= date('M d, Y', strtotime($file['created_at'] ?? 'now')) ?></div>
                        <div class="file-actions">
                            <a href="download.php?id=<?= $file['id'] ?>" class="action-btn">Download</a>
                            <a href="home.php?delete_id=<?= $file['id'] ?><?= $current_folder_id ? '&folder_id='.$current_folder_id : '' ?>" class="action-btn delete" onclick="return confirm('Are you sure you want to delete this file?');">Delete</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <?php if (empty($folders)): ?>
                <p>This folder is empty. Upload files or create folders to get started.</p>
            <?php else: ?>
                <p>No files in this folder.</p>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <!-- JavaScript to hide messages after 3 seconds -->
    <script>
    function hideMessage(elementId, delay) {
        setTimeout(function() {
            var messageElement = document.getElementById(elementId);
            if (messageElement) {
                messageElement.style.display = 'none';
            }
        }, delay);
    }

    // Hide upload message after 3 seconds
    hideMessage('upload-message', 3000);

    // Hide delete message after 3 seconds
    hideMessage('delete-message', 3000);

    // Hide folder message after 3 seconds
    hideMessage('folder-message', 3000);
    </script>
</body>
</html>
