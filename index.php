<?php
// Evitar cache para desarrollo
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

// Establecer zona horaria
date_default_timezone_set('America/Hermosillo');

// Conexión a la base de datos
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "asistencia_db";

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// Iniciar sesión
session_start();

// Incluir PhpSpreadsheet
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Determinar turno actual según la hora
$hora_actual = date('H:i');
$turno_actual = ($hora_actual < '13:40') ? 'matutino' : 'vespertino';

// Procesar login
$login_error = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['email'])) {
    $email = $conn->real_escape_string($_POST['email']);
    $password = $_POST['password'];
    
    $sql = "SELECT * FROM usuarios WHERE email = '$email'";
    $result = $conn->query($sql);
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user'] = $user;
            header("Location: index.php?" . time());
            exit();
        } else {
            $login_error = "Contraseña incorrecta";
        }
    } else {
        $login_error = "Usuario no encontrado";
    }
}

// Función para enviar correo con PHPMailer
function enviarCorreo($destinatarios, $asunto, $cuerpo, $archivoAdjunto = null) {
    
    $mail = new PHPMailer(true);
    
    try {
        // Configuración del servidor SMTP (AJUSTAR ESTOS VALORES)
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';  // Servidor SMTP
        $mail->SMTPAuth   = true;
        $mail->Username   = 'zonaxsuper@gmail.com';  // Tu correo
        $mail->Password   = 'nrku xhbf rssf saul';  // Tu contraseña de aplicación
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        
        // Remitente
        $mail->setFrom('tu_correo@gmail.com', 'Sistema de Asistencia Zona X');
        
        // Destinatarios
        foreach ($destinatarios as $destinatario) {
            $mail->addAddress($destinatario);
        }
        
        // Contenido
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = $cuerpo;
        $mail->AltBody = strip_tags($cuerpo);
        
        // Adjuntar archivo si se proporciona
        if ($archivoAdjunto && file_exists($archivoAdjunto)) {
            $mail->addAttachment($archivoAdjunto);
        }
        
        // Enviar correo
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Error al enviar correo: {$mail->ErrorInfo}");
        return false;
    }
}

// Función para enviar concentrado por correo
function enviarConcentradoPorCorreo($rutaArchivo, $turno) {
    global $conn;
    
    // Obtener destinatarios de la base de datos
    $destinatarios = array();
    $sql = "SELECT email FROM destinatarios";
    $result = $conn->query($sql);
    
    while($row = $result->fetch_assoc()) {
        $destinatarios[] = $row['email'];
    }
    
    if (empty($destinatarios)) {
        return "No hay destinatarios configurados";
    }
    
    $asunto = "Concentrado de Asistencia - Turno " . ucfirst($turno) . " - " . date('d/m/Y');
    $cuerpo = "
        <h2>Concentrado de Asistencia</h2>
        <p>Se adjunta el concentrado del turno <strong>" . ucfirst($turno) . "</strong> 
        correspondiente a la fecha <strong>" . date('d/m/Y') . "</strong>.</p>
        <p>Saludos,<br>Sistema de Asistencia Zona X</p>
    ";
    
    if (enviarCorreo($destinatarios, $asunto, $cuerpo, $rutaArchivo)) {
        return "Correo enviado exitosamente";
    } else {
        return "Error al enviar el correo";
    }
}

// Procesar subida de archivos
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["excelFile"])) {
    if (!isset($_SESSION['user'])) {
        die("No autenticado");
    }
    
    // Validar si está dentro del horario permitido
    $turno_subida = $_POST["turno"];
    $hora_actual = date('H:i');
    
    if ($turno_subida == 'matutino' && $hora_actual > '13:40') {
        echo "<script>alert('El turno matutino ha finalizado. No se pueden subir archivos para este turno.');</script>";
        echo "<script>window.location.href = 'index.php?" . time() . "';</script>";
        exit();
    }
    
    // Validar tipo de archivo
    $allowed_types = ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'];
    $file_type = $_FILES["excelFile"]["type"];
    
    if (!in_array($file_type, $allowed_types)) {
        echo "<script>alert('Solo se permiten archivos Excel.');</script>";
        echo "<script>window.location.href = 'index.php?" . time() . "';</script>";
        exit();
    }
    
    $target_dir = "uploads/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $schoolId = $_POST["schoolId"];
    $turno = $_POST["turno"];
    $fecha = date('Y-m-d');
    $target_file = $target_dir . $schoolId . "_" . $turno . "_" . $fecha . "_" . basename($_FILES["excelFile"]["name"]);
    
    if (move_uploaded_file($_FILES["excelFile"]["tmp_name"], $target_file)) {
        // Procesar el archivo Excel con PhpSpreadsheet
        try {
            $spreadsheet = IOFactory::load($target_file);
            $worksheet = $spreadsheet->getActiveSheet();
            
            // Guardar en base de datos
            $nombre_archivo = basename($_FILES["excelFile"]["name"]);
            $ruta_archivo = $target_file;
            $escuela_id = $schoolId;
            
            // Verificar si ya existe un archivo para este turno y escuela hoy
            $sql_check = "SELECT id FROM archivos WHERE escuela_id = '$escuela_id' AND turno = '$turno' AND DATE(fecha_subida) = '$fecha'";
            $result_check = $conn->query($sql_check);
            
            if ($result_check->num_rows > 0) {
                // Actualizar archivo existente
                $row = $result_check->fetch_assoc();
                $file_id = $row['id'];
                $sql = "UPDATE archivos SET nombre_archivo = '$nombre_archivo', ruta_archivo = '$ruta_archivo', fecha_subida = NOW() WHERE id = $file_id";
            } else {
                // Insertar nuevo archivo
                $sql = "INSERT INTO archivos (escuela_id, turno, nombre_archivo, ruta_archivo, fecha_subida) 
                        VALUES ('$escuela_id', '$turno', '$nombre_archivo', '$ruta_archivo', NOW())";
            }
            
            if ($conn->query($sql)) {
                echo "<script>alert('Archivo subido y procesado correctamente');</script>";
                echo "<script>window.location.href = 'index.php?" . time() . "';</script>";
                exit();
            } else {
                echo "<script>alert('Error al guardar en base de datos: " . $conn->error . "');</script>";
            }
        } catch (Exception $e) {
            echo "<script>alert('Error al procesar el archivo Excel: " . $e->getMessage() . "');</script>";
        }
    } else {
        echo "<script>alert('Error al subir archivo');</script>";
    }
}

// Procesar eliminación de archivos (solo admin)
if (isset($_GET['delete_file']) && isset($_SESSION['user']) && $_SESSION['user']['rol'] === 'admin') {
    $file_id = $_GET['delete_file'];
    
    // Obtener información del archivo
    $sql = "SELECT * FROM archivos WHERE id = $file_id";
    $result = $conn->query($sql);
    
    if ($result->num_rows > 0) {
        $file = $result->fetch_assoc();
        
        // Eliminar archivo físico
        if (file_exists($file['ruta_archivo'])) {
            unlink($file['ruta_archivo']);
        }
        
        // Eliminar registro de la base de datos
    $sql = "DELETE FROM archivos WHERE id = $file_id";
    if ($conn->query($sql)) {
        echo "<script>alert('Archivo eliminado correctamente');</script>";
    } else {
        echo "<script>alert('Error al eliminar archivo de la base de datos');</script>";
    }
}

echo "<script>window.location.href = 'index.php?" . time() . "';</script>";
exit();
}

// Procesar gestión de destinatarios
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_destinatarios'])) {
    if (!isset($_SESSION['user']) || $_SESSION['user']['rol'] !== 'admin') {
        die("No autorizado");
    }
    
    $action = $_POST['action_destinatarios'];
    
    if ($action == 'agregar') {
        $email = $conn->real_escape_string($_POST['email']);
        
        $sql = "INSERT INTO destinatarios (email) VALUES ('$email')";
        if ($conn->query($sql)) {
            echo "<script>alert('Destinatario agregado correctamente');</script>";
        } else {
            echo "<script>alert('Error al agregar destinatario');</script>";
        }
    } 
    elseif ($action == 'editar') {
        $id = $_POST['id'];
        $email = $conn->real_escape_string($_POST['email']);
        
        $sql = "UPDATE destinatarios SET email='$email' WHERE id=$id";
        if ($conn->query($sql)) {
            echo "<script>alert('Destinatario actualizado correctamente');</script>";
        } else {
            echo "<script>alert('Error al actualizar destinatario');</script>";
        }
    }
    elseif ($action == 'eliminar') {
        $id = $_POST['id'];
        
        $sql = "DELETE FROM destinatarios WHERE id=$id";
        if ($conn->query($sql)) {
            echo "<script>alert('Destinatario eliminado correctamente');</script>";
        } else {
            echo "<script>alert('Error al eliminar destinatario');</script>";
        }
    }
    
    echo "<script>window.location.href = 'index.php?" . time() . "';</script>";
    exit();
}

// Procesar consolidación de archivos por turno
if (isset($_GET['action']) && $_GET['action'] == 'merge' && isset($_SESSION['user']) && $_SESSION['user']['rol'] === 'admin') {
    $hora_actual = date('H:i');
    $turno = ($hora_actual < '09:00') ? 'matutino' : 'vespertino';
    
    if (empty($turno)) {
        echo "<script>alert('Debe especificar un turno para consolidar');</script>";
        echo "<script>window.location.href = 'index.php?" . time() . "';</script>";
        exit();
    }
    
    // Obtener archivos del día y turno específico
    $hoy = date('Y-m-d');
    $sql = "SELECT * FROM archivos WHERE DATE(fecha_subida) = '$hoy' AND turno = '$turno'";
    $result = $conn->query($sql);
    
    $archivos = [];
    while ($row = $result->fetch_assoc()) {
        $archivos[] = $row;
    }
    
    if (count($archivos) > 0) {
        // Crear un nuevo libro de Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Consolidado ' . ucfirst($turno));
        
        // Encabezados
        $sheet->setCellValue('A1', 'Escuela');
        $sheet->setCellValue('B1', 'Turno');
        $sheet->setCellValue('C1', 'Archivo');
        $sheet->setCellValue('D1', 'Fecha');
        
        $rowCount = 2;
        foreach ($archivos as $archivo) {
            $sheet->setCellValue('A' . $rowCount, $archivo['escuela_id']);
            $sheet->setCellValue('B' . $rowCount, $archivo['turno']);
            $sheet->setCellValue('C' . $rowCount, $archivo['nombre_archivo']);
            $sheet->setCellValue('D' . $rowCount, $archivo['fecha_subida']);
            $rowCount++;
        }
        
        // Guardar el archivo consolidado
        $consolidated_dir = "consolidated/";
        if (!file_exists($consolidated_dir)) {
            mkdir($consolidated_dir, 0777, true);
        }
        
        $filename = 'consolidado_' . $turno . '_' . $hoy . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($consolidated_dir . $filename);
        
        // Guardar referencia en la base de datos
        $sql = "INSERT INTO consolidados (nombre_archivo, ruta_archivo, turno, fecha_creacion) 
                VALUES ('$filename', '$consolidated_dir$filename', '$turno', NOW())";
        $conn->query($sql);
        
        // Enviar por correo
        $resultadoEnvio = enviarConcentradoPorCorreo($consolidated_dir . $filename, $turno);
        
        echo "<script>alert('Archivos del turno " . $turno . " consolidados correctamente. $resultadoEnvio');</script>";
        echo "<script>window.location.href = 'index.php?" . time() . "';</script>";
    } else {
        echo "<script>alert('No hay archivos del turno " . $turno . " para consolidar hoy.');</script>";
        echo "<script>window.location.href = 'index.php?" . time() . "';</script>";
    }
}

// Cerrar sesión
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php?" . time());
    exit();
}

// Obtener archivos subidos hoy
$archivos_hoy = array();
if (isset($_SESSION['user'])) {
    $hoy = date('Y-m-d');
    $sql = "SELECT * FROM archivos WHERE DATE(fecha_subida) = '$hoy'";
    $result = $conn->query($sql);
    
    while($row = $result->fetch_assoc()) {
        $archivos_hoy[$row['escuela_id']][$row['turno']] = $row;
    }
}

// Obtener historial (última semana)
$historial = array();
$semana_pasada = date('Y-m-d', strtotime('-7 days'));
$sql = "SELECT a.*, u.nombre as escuela_nombre 
        FROM archivos a 
        LEFT JOIN usuarios u ON a.escuela_id = u.escuela_id 
        WHERE a.fecha_subida >= '$semana_pasada' 
        ORDER BY a.fecha_subida DESC";
$result = $conn->query($sql);

while($row = $result->fetch_assoc()) {
    $historial[] = $row;
}

// Obtener archivos consolidados
$consolidados = array();
if (isset($_SESSION['user']) && $_SESSION['user']['rol'] === 'admin') {
    $sql = "SELECT * FROM consolidados ORDER BY fecha_creacion DESC LIMIT 10";
    $result = $conn->query($sql);
    
    while($row = $result->fetch_assoc()) {
        $consolidados[] = $row;
    }
}

// Obtener destinatarios
$destinatarios = array();
if (isset($_SESSION['user']) && $_SESSION['user']['rol'] === 'admin') {
    $sql = "SELECT * FROM destinatarios ORDER BY id";
    $result = $conn->query($sql);
    
    while($row = $result->fetch_assoc()) {
        $destinatarios[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Asistencia - ZONA X</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <style>
        /* Estilos adicionales para el input de archivo */
        .modal .form-group {
            margin-left: 30px;
            margin-right: 30px;
        }
        
        /* Estilo para ocultar botones de subida después de seleccionar archivo */
        .hidden {
            display: none !important;
        }
        
        /* Nuevos estilos para mejorar la interfaz */
        .status-updated {
            animation: statusUpdate 0.5s ease-in-out;
        }
        
        @keyframes statusUpdate {
            0% { background-color: #ffffff; }
            50% { background-color: #d4edda; }
            100% { background-color: #ffffff; }
        }
        
        .uploading {
            position: relative;
            opacity: 0.7;
        }
        
        .uploading::after {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        /* Alinear iconos horizontalmente */
        .status-icons {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: nowrap;
        }
        
        .history-table td:last-child {
            white-space: nowrap;
        }
        
        .history-table .btn-icon {
            display: inline-flex;
            margin: 0 2px;
        }
        
        @media (max-width: 768px) {
            .upload-status {
                flex-direction: row;
                align-items: center;
            }
            
            .status {
                margin-right: auto;
            }
            
            .status-icons {
                margin-top: 0;
            }
            
            .history-table .btn-icon {
                display: inline-flex;
                margin: 0 2px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container header-content">
            <div class="logo" onclick="window.location.href='index.php'">
                <img src="assets/logo.png" alt="Logo SEC Sonora" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI1MCIgaGVpZ2h0PSI1MCIgdmlld0JveD0iMCAwIDI0IDI4IiBmaWxsPSJub25lIiBzdHJva2U9IiM3YTFjNGEiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InRvdW5kIj48cGF0aCBkPSJNMTAgMTNhNSA1IDAgMCAwIDcgMGwzLTMiPjxwYXRoIGQ9Ik0xNCAxMWE1IDUgMCAwIDAtNyAwbC0zIDMiPjxwYXRoIGQ9Ik0yIDhhOCA4IDAgMCAxIDEwLjg3NDcuNDQ5IiAvPjxwYXRoIGQ9Ik01IDRoMTRhMiAyIDAgMCAxIDIgMnY0YTIgMiAwIDAgMS0yIDJINWEyIDIgMCAwIDEtMi0yVjZhMiAyIDAgMCAxIDItMnoiLz48L3N2Zz4='">
                <div class="logo-text">
                    <h1>SUPERVISIÓN ZONA X</h1>
                    <p>C. Mtra. Griselda Zaiz Cruz</p>
                </div>
            </div>
            <div class="auth-buttons">
                <?php if (isset($_SESSION['user'])): ?>
                    <button class="btn btn-outline" onclick="location.href='?logout=true'">Cerrar Sesión</button>
                <?php else: ?>
                    <button class="btn btn-primary" onclick="showLogin()">Iniciar Sesión</button>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="container main-content">
        <?php if (!isset($_SESSION['user'])): ?>
            <!-- Login Form -->
            <div id="loginPage" class="login-container">
                <div class="login-box">
                    <div class="login-header">
                        <h2>Iniciar Sesión</h2>
                        <p>Ingrese sus credenciales para acceder al sistema</p>
                        <?php if (!empty($login_error)): ?>
                            <div class="error-message"><?php echo $login_error; ?></div>
                        <?php endif; ?>
                    </div>
                    <form id="loginForm" method="POST" action="">
                        <div class="form-group">
                            <label for="email">Correo Electrónico</label>
                            <input type="email" id="email" name="email" class="form-control" placeholder="usuario@zonax.com" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Contraseña</label>
                            <input type="password" id="password" name="password" class="form-control" placeholder="Ingrese su contraseña" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">Ingresar</button>
                    </form>
                    <div class="login-footer">
                        <p>¿Problemas para acceder?</p>
                        <p>Soporte cel. 6622293879</p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Dashboard -->
            <div id="dashboardPage">
                <div class="hero">
                    <h2>Reportes de Asistencia Diaria</h2>
                    <p id="welcomeMessage">Bienvenido/a, <?php echo $_SESSION['user']['nombre']; ?></p>
                    <div id="turnInfo" class="turn-info">
                        <h3>Turno Actual: <span id="currentTurn"><?php echo ucfirst($turno_actual); ?></span></h3>
                        <p>Horario: <span id="turnTime"><?php echo ($turno_actual == 'matutino') ? '07:30 AM' : '01:40 PM'; ?></span></p>
                        <p id="nextTurnInfo"><?php echo ($turno_actual == 'matutino') ? 'Próximo turno: Vespertino a las 13:40' : 'Turno vespertino en curso'; ?></p>
                    </div>
                </div>

                <?php if ($_SESSION['user']['rol'] === 'admin'): ?>
                    <!-- Vista Admin -->
                    <div class="dashboard" id="schoolDashboard">
                        <?php
                        $schools = [
                            '26DST0006K' => 'Secundaria Técnica #6',
                            '26DST0060K' => 'Secundaria Técnica #60', 
                            '26DST0072K' => 'Secundaria Técnica #72'
                        ];
                        
                        foreach ($schools as $id => $name): 
                            $hasMatutino = isset($archivos_hoy[$id]['matutino']);
                            $hasVespertino = isset($archivos_hoy[$id]['vespertino']);
                        ?>
                            <div class="card" id="card-<?php echo $id; ?>">
                                <div class="card-header"><?php echo $name; ?></div>
                                <div class="card-body">
                                    <div class="school-info">
                                        <div class="school-icon"><i class="fas fa-school"></i></div>
                                        <div class="school-details">
                                            <h3><?php echo $id; ?></h3>
                                            <p>Reportes del día <?php echo date('d/m/Y'); ?></p>
                                        </div>
                                    </div>
                                    
                                    <div class="turno-status">
                                        <h4>Turno Matutino</h4>
                                        <div class="upload-status" id="status-<?php echo $id; ?>-matutino">
                                            <span class="status <?php echo $hasMatutino ? 'status-completed' : 'status-pending'; ?>">
                                                <?php echo $hasMatutino ? 'Completado' : 'Pendiente'; ?>
                                            </span>
                                            <div class="status-icons">
                                                <?php if ($hasMatutino): ?>
                                                    <button class="view-file-btn" onclick="viewFile('<?php echo $archivos_hoy[$id]['matutino']['ruta_archivo']; ?>', '<?php echo $name; ?> - Matutino', <?php echo $archivos_hoy[$id]['matutino']['id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="btn btn-outline" onclick="downloadFile('<?php echo $archivos_hoy[$id]['matutino']['ruta_archivo']; ?>', '<?php echo $archivos_hoy[$id]['matutino']['nombre_archivo']; ?>')">
                                                        <i class="fas fa-download"></i>
                                                    </button>
                                                    <button class="btn btn-primary" onclick="openUploadModal('matutino', '<?php echo $id; ?>')">Reemplazar</button>
                                                <?php else: ?>
                                                    <button class="btn btn-primary upload-btn" id="uploadBtnMatutino<?php echo $id; ?>" onclick="openUploadModal('matutino', '<?php echo $id; ?>')">Subir Reporte</button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <h4>Turno Vespertino</h4>
                                        <div class="upload-status" id="status-<?php echo $id; ?>-vespertino">
                                            <span class="status <?php echo $hasVespertino ? 'status-completed' : 'status-pending'; ?>">
                                                <?php echo $hasVespertino ? 'Completado' : 'Pendiente'; ?>
                                            </span>
                                            <div class="status-icons">
                                                <?php if ($hasVespertino): ?>
                                                    <button class="view-file-btn" onclick="viewFile('<?php echo $archivos_hoy[$id]['vespertino']['ruta_archivo']; ?>', '<?php echo $name; ?> - Vespertino', <?php echo $archivos_hoy[$id]['vespertino']['id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="btn btn-outline" onclick="downloadFile('<?php echo $archivos_hoy[$id]['vespertino']['ruta_archivo']; ?>', '<?php echo $archivos_hoy[$id]['vespertino']['nombre_archivo']; ?>')">
                                                        <i class="fas fa-download"></i>
                                                    </button>
                                                    <button class="btn btn-primary" onclick="openUploadModal('vespertino', '<?php echo $id; ?>')">Reemplazar</button>
                                                <?php else: ?>
                                                    <button class="btn btn-primary upload-btn" id="uploadBtnVespertino<?php echo $id; ?>" onclick="openUploadModal('vespertino', '<?php echo $id; ?>')">Subir Reporte</button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <!-- Vista Escuela -->
                    <?php 
                    $schoolId = $_SESSION['user']['escuela_id'];
                    $schoolName = $_SESSION['user']['nombre'];
                    $hasMatutino = isset($archivos_hoy[$schoolId]['matutino']);
                    $hasVespertino = isset($archivos_hoy[$schoolId]['vespertino']);
                    ?>
                    <div class="school-view-container">
                        <div class="card school-view-card" id="card-<?php echo $schoolId; ?>">
                            <div class="card-header"><?php echo $schoolName; ?></div>
                            <div class="card-body">
                                <div class="school-info">
                                    <div class="school-icon"><i class="fas fa-school"></i></div>
                                    <div class="school-details">
                                        <h3><?php echo $schoolId; ?></h3>
                                        <p>Reportes del día <?php echo date('d/m/Y'); ?></p>
                                    </div>
                                </div>
                                
                                <div class="turno-status">
                                    <?php if ($turno_actual == 'matutino' || $_SESSION['user']['rol'] === 'admin'): ?>
                                    <h4>Turno Matutino</h4>
                                    <div class="upload-status" id="status-<?php echo $schoolId; ?>-matutino">
                                        <span class="status <?php echo $hasMatutino ? 'status-completed' : 'status-pending'; ?>">
                                            <?php echo $hasMatutino ? 'Completado' : 'Pendiente'; ?>
                                        </span>
                                        <div class="status-icons">
                                            <?php if ($hasMatutino): ?>
                                                <button class="view-file-btn" onclick="viewFile('<?php echo $archivos_hoy[$schoolId]['matutino']['ruta_archivo']; ?>', 'Matutino', <?php echo $archivos_hoy[$schoolId]['matutino']['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-outline" onclick="downloadFile('<?php echo $archivos_hoy[$schoolId]['matutino']['ruta_archivo']; ?>', '<?php echo $archivos_hoy[$schoolId]['matutino']['nombre_archivo']; ?>')">
                                                    <i class="fas fa-download"></i>
                                                </button>
                                                <?php if ($turno_actual == 'matutino'): ?>
                                                    <button class="btn btn-primary" onclick="openUploadModal('matutino', '<?php echo $schoolId; ?>')">Reemplazar</button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if ($turno_actual == 'matutino'): ?>
                                                    <button class="btn btn-primary upload-btn" id="uploadBtnMatutinoSchool" onclick="openUploadModal('matutino', '<?php echo $schoolId; ?>')">Subir Reporte</button>
                                                <?php else: ?>
                                                    <span class="status status-expired">Turno finalizado</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($turno_actual == 'vespertino' || $_SESSION['user']['rol'] === 'admin'): ?>
                                    <h4>Turno Vespertino</h4>
                                    <div class="upload-status" id="status-<?php echo $schoolId; ?>-vespertino">
                                        <span class="status <?php echo $hasVespertino ? 'status-completed' : 'status-pending'; ?>">
                                            <?php echo $hasVespertino ? 'Completado' : 'Pendiente'; ?>
                                        </span>
                                        <div class="status-icons">
                                            <?php if ($hasVespertino): ?>
                                                <button class="view-file-btn" onclick="viewFile('<?php echo $archivos_hoy[$schoolId]['vespertino']['ruta_archivo']; ?>', 'Vespertino', <?php echo $archivos_hoy[$schoolId]['vespertino']['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-outline" onclick="downloadFile('<?php echo $archivos_hoy[$schoolId]['vespertino']['ruta_archivo']; ?>', '<?php echo $archivos_hoy[$schoolId]['vespertino']['nombre_archivo']; ?>')">
                                                    <i class="fas fa-download"></i>
                                                </button>
                                                <?php if ($turno_actual == 'vespertino'): ?>
                                                    <button class="btn btn-primary" onclick="openUploadModal('vespertino', '<?php echo $schoolId; ?>')">Reemplazar</button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if ($turno_actual == 'vespertino'): ?>
                                                    <button class="btn btn-primary upload-btn" id="uploadBtnVespertinoSchool" onclick="openUploadModal('vespertino', '<?php echo $schoolId; ?>')">Subir Reporte</button>
                                                <?php else: ?>
                                                    <span class="status status-expired">Turno no iniciado</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($_SESSION['user']['rol'] === 'admin'): ?>
                    <div id="adminPanel">
                        <div class="merge-section">
                            <h3>Consolidar Reportes</h3>
                            <div class="merge-action">
                                <?php
                                $hora_actual = date('H:i');
                                $turno_consolidar = ($hora_actual < '09:00') ? 'matutino' : 'vespertino';
                                $texto_turno = ($hora_actual < '09:00') ? 'Matutinos' : 'Vespertinos';
                                ?>
                                <button class="btn consolidate-btn" id="mergeBtn" onclick="mergeFiles('<?php echo $turno_consolidar; ?>')">
                                    Adjuntar Archivos <?php echo $texto_turno; ?>
                                </button>
                                <div class="spinner" id="mergeSpinner"></div>
                            </div>
                            
                            <?php if (!empty($consolidados)): ?>
                                <div class="consolidados-list">
                                    <h4>Archivos consolidados recientes:</h4>
                                    <ul>
                                        <?php foreach ($consolidados as $consolidado): ?>
                                            <li>
                                                <?php echo $consolidado['nombre_archivo']; ?> 
                                                (<?php echo date('d/m/Y H:i', strtotime($consolidado['fecha_creacion'])); ?>)
                                                <button class="btn btn-outline" onclick="downloadFile('<?php echo $consolidado['ruta_archivo']; ?>', '<?php echo $consolidado['nombre_archivo']; ?>')">
                                                    <i class="fas fa-download"></i> Descargar
                                                </button>
                                                <button class="btn btn-outline" onclick="viewFile('<?php echo $consolidado['ruta_archivo']; ?>', 'Consolidado <?php echo $consolidado['turno']; ?>')">
                                                    <i class="fas fa-eye"></i> Vista previa
                                                </button>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Gestión de destinatarios -->
                        <div class="destinatarios-container">
                            <h3>Gestión de Destinatarios</h3>
                            <p>Lista de correos a los que se enviarán los concentrados</p>
                            
                            <!-- Formulario para agregar destinatario -->
                            <form method="POST" action="" class="form-inline">
                                <input type="hidden" name="action_destinatarios" value="agregar">
                                <input type="email" name="email" class="form-control" placeholder="Correo electrónico" required>
                                <button type="submit" class="btn btn-primary">Agregar</button>
                            </form>
                            
                            <!-- Lista de destinatarios -->
                            <div class="destinatarios-list">
                                <?php if (!empty($destinatarios)): ?>
                                    <?php foreach ($destinatarios as $destinatario): ?>
                                        <div class="destinatario-item">
                                            <div class="destinatario-info">
                                                <div><?php echo htmlspecialchars($destinatario['email']); ?></div>
                                            </div>
                                            <div class="destinatario-actions">
                                                <button class="btn btn-outline btn-sm" onclick="editarDestinatario(<?php echo $destinatario['id']; ?>, '<?php echo $destinatario['email']; ?>')">
                                                    <i class="fas fa-edit"></i> Editar
                                                </button>
                                                <form method="POST" action="" style="display:inline;">
                                                    <input type="hidden" name="action_destinatarios" value="eliminar">
                                                    <input type="hidden" name="id" value="<?php echo $destinatario['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('¿Está seguro de eliminar este destinatario?')">
                                                        <i class="fas fa-trash"></i> Eliminar
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p>No hay destinatarios registrados.</p>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Botón para enviar concentrado -->
                            <div class="enviar-concentrado">
                                <button class="btn btn-success" onclick="enviarConcentrado()">
                                    <i class="fas fa-paper-plane"></i> Enviar Concentrado
                                </button>
                            </div>
                        </div>

                        <div class="history-section">
                            <h3>Historial de Reportes (Última Semana)</h3>
                            <div class="history-table-container">
                                <table class="history-table">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Escuela</th>
                                            <th class="desktop-only">Turno</th>
                                            <th class="desktop-only">Archivo</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($historial as $reporte): ?>
                                            <tr>
                                                <td>
                                                    <?php echo date('d/m/Y H:i', strtotime($reporte['fecha_subida'])); ?>
                                                    <span class="mobile-turno-info"><?php echo ucfirst($reporte['turno']); ?></span>
                                                </td>
                                                <td><?php echo $reporte['escuela_nombre']; ?></td>
                                                <td class="desktop-only"><?php echo ucfirst($reporte['turno']); ?></td>
                                                <td class="desktop-only"><?php echo $reporte['nombre_archivo']; ?></td>
                                                <td>
                                                    <button class="btn-icon" onclick="viewFile('<?php echo $reporte['ruta_archivo']; ?>', '<?php echo $reporte['escuela_nombre']; ?>', <?php echo $reporte['id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="btn-icon" onclick="downloadFile('<?php echo $reporte['ruta_archivo']; ?>', '<?php echo $reporte['nombre_archivo']; ?>')">
                                                        <i class="fas fa-download"></i>
                                                    </button>
                                                    <?php if ($_SESSION['user']['rol'] === 'admin'): ?>
                                                        <button class="btn-icon" onclick="deleteFile(<?php echo $reporte['id']; ?>)">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Upload Modal -->
    <div class="modal" id="uploadModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="uploadModalTitle">Subir Reporte de Asistencia</h3>
                <button type="button" class="btn-icon close-modal" onclick="closeUploadModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="uploadForm" method="POST" action="" enctype="multipart/form-data" onsubmit="handleUploadSubmit(event)">
                <input type="hidden" id="schoolIdInput" name="schoolId" value="<?php echo isset($_SESSION['user']) ? $_SESSION['user']['escuela_id'] : ''; ?>">
                <input type="hidden" id="turnoInput" name="turno" value="">
                <div class="form-group">
                    <label for="excelFile">Seleccione archivo Excel</label>
                    <input type="file" id="excelFile" name="excelFile" accept=".xlsx, .xls" required>
                    <p class="file-type-warning">Solo se permiten archivos .xlsx or .xls</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeUploadModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="uploadSubmitBtn">Subir Archivo</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View File Modal -->
    <div class="modal" id="viewFileModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="viewFileModalTitle">Documento Subido</h3>
                <button type="button" class="btn-icon close-modal" onclick="closeViewFileModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="fileViewContent">
                    <p>Aquí se mostrará el documento subido.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeViewFileModal()">Cerrar</button>
                <button type="button" class="btn btn-primary" onclick="downloadCurrentFile()">Descargar Archivo</button>
                <button type="button" class="btn btn-danger" id="deleteFileBtn" style="display:none;" onclick="deleteCurrentFile()">
                    <i class="fas fa-trash"></i> Eliminar Archivo
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Destinatario Modal -->
    <div class="modal" id="editDestinatarioModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Editar Destinatario</h3>
                <button type="button" class="btn-icon close-modal" onclick="closeEditDestinatarioModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" action="" id="editDestinatarioForm">
                <input type="hidden" name="action_destinatarios" value="editar">
                <input type="hidden" name="id" id="editDestinatarioId" value="">
                <div class="form-group">
                    <label for="editEmail">Correo electrónico</label>
                    <input type="email" id="editEmail" name="email" class="form-control" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeEditDestinatarioModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Footer -->
    <footer>
        <div class="container footer-content">
            <div class="footer-section">
                <h4>Secretaría de Educación y Cultura del Estado de Sonora.</h4>
                <p>Dirección de Educación Secundaria Técnica.</p>
                <p>Hermosillo, Sonora, México</p>
            </div>
        </div>
        <div class="footer-bottom">
            <p>© 2025 SUPERVISIÓN ZONA X - TODOS LOS DERECHOS RESERVADOS.</p>
        </div>
    </footer>
    <script>
       const userSchoolName = '<?php echo isset($_SESSION["user"]) ? $_SESSION["user"]["nombre"] : ""; ?>';
       const isUserAdmin = '<?php echo isset($_SESSION["user"]) && $_SESSION["user"]["rol"] === "admin" ? "true" : "false"; ?>';
    </script>
<script src="script.js?v=<?php echo time(); ?>"></script>

    <script src="script.js?v=<?php echo time(); ?>"></script>
    <script>
        // Variables globales
        let currentViewingFile = null;
        let currentFileName = null;
        let currentSchoolId = null;
        let currentTurno = null;
        let currentFileId = null;

        // Función para abrir el modal de subida de archivos
        function openUploadModal(turno, schoolId = null) {
            const isAdmin = '<?php echo isset($_SESSION["user"]) && $_SESSION["user"]["rol"] === "admin" ? "true" : "false"; ?>';
            
            let title = "Subir Reporte - ";
            
            if (isAdmin === 'true' && schoolId) {
                // Para admin, mostrar a qué escuela está subiendo
                const schoolNames = {
                    '26DST0006K': 'Secundaria Técnica #6',
                    '26DST0060K': 'Secundaria Técnica #60', 
                    '26DST0072K': 'Secundaria Técnica #72'
                };
                title += schoolNames[schoolId] + " - Turno " + turno;
            } else {
                const schoolName = '<?php echo isset($_SESSION["user"]) ? $_SESSION["user"]["nombre"] : ""; ?>';
                title += schoolName + " - Turno " + turno;
            }
            
            document.getElementById('uploadModalTitle').textContent = title;
            document.getElementById('turnoInput').value = turno;
            document.getElementById('schoolIdInput').value = schoolId;
            document.getElementById('uploadModal').style.display = 'flex';
            
            // Guardar para uso posterior
            currentSchoolId = schoolId;
            currentTurno = turno;
        }

        // Función para manejar el envío del formulario de subida
        function handleUploadSubmit(event) {
            event.preventDefault();
            
            const submitBtn = document.getElementById('uploadSubmitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Subiendo...';
            
            // Mostrar estado de carga en la interfaz
            const schoolId = document.getElementById('schoolIdInput').value;
            const turno = document.getElementById('turnoInput').value;
            const statusElement = document.getElementById(`status-${schoolId}-${turno}`);
            
            if (statusElement) {
                statusElement.classList.add('uploading');
            }
            
            // Enviar formulario
            const formData = new FormData(event.target);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                // Recargar la página para mostrar los cambios
                window.location.href = 'index.php?' + new Date().getTime();
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al subir el archivo');
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'Subir Archivo';
                
                // Quitar estado de carga
                if (statusElement) {
                    statusElement.classList.remove('uploading');
                }
            });
        }

        // Función para cerrar el modal de subida de archivos
        function closeUploadModal() {
            document.getElementById('uploadModal').style.display = 'none';
            document.getElementById('uploadForm').reset();
            
            const submitBtn = document.getElementById('uploadSubmitBtn');
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Subir Archivo';
        }

        // Función para ver un archivo
        function viewFile(filePath, title, fileId = null) {
            currentViewingFile = filePath;
            const fileName = filePath.split('/').pop();
            currentFileName = fileName;
            currentFileId = fileId;
            
            document.getElementById('viewFileModalTitle').textContent = title;
            
            // Obtener información del archivo
            const fileSize = getFileSize(filePath);
            
            document.getElementById('fileViewContent').innerHTML = `
                <div class="file-info">
                    <p><strong>Nombre del archivo:</strong> ${fileName}</p>
                    <p><strong>Tamaño:</strong> ${fileSize}</p>
                    <p><strong>Ubicación:</strong> ${filePath}</p>
                </div>
            `;
            
            // Mostrar u ocultar botón de eliminar según permisos
            const isAdmin = '<?php echo isset($_SESSION["user"]) && $_SESSION["user"]["rol"] === "admin" ? "true" : "false"; ?>';
            const deleteBtn = document.getElementById('deleteFileBtn');
            
            if (isAdmin === 'true' && fileId) {
                deleteBtn.style.display = 'inline-block';
            } else {
                deleteBtn.style.display = 'none';
            }
            
            document.getElementById('viewFileModal').style.display = 'flex';
        }

        // Función para obtener el tamaño del archivo
        function getFileSize(filePath) {
            // Esta función simula obtener el tamaño del archivo
            // En un sistema real, usarías PHP para obtener esta información
            return 'N/A';
        }

        // Función para cerrar el modal de visualización de archivo
        function closeViewFileModal() {
            document.getElementById('viewFileModal').style.display = 'none';
            currentViewingFile = null;
            currentFileName = null;
            currentFileId = null;
        }

        // Función para descargar el archivo actual
        function downloadCurrentFile() {
            if (currentViewingFile) {
                downloadFile(currentViewingFile, currentFileName);
            }
        }

        // Función para eliminar el archivo actual
        function deleteCurrentFile() {
            if (currentFileId) {
                deleteFile(currentFileId);
            }
        }

        // Función para descargar un archivo
        function downloadFile(filePath, fileName) {
            // Crear un enlace temporal para descargar el archivo
            const link = document.createElement('a');
            link.href = 'download.php?file=' + encodeURIComponent(filePath);
            link.download = fileName;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Función para eliminar archivo
        function deleteFile(fileId) {
            if (confirm('¿Está seguro de eliminar este archivo? Esta acción no se puede deshacer.')) {
                window.location.href = `?delete_file=${fileId}`;
            }
        }

        // Función para combinar archivos por turno
        function mergeFiles(turno) {
            if (confirm('¿Está seguro de que desea consolidar los archivos del turno ' + turno + '?')) {
                const mergeBtn = document.getElementById('mergeBtn');
                const spinner = document.getElementById('mergeSpinner');
                
                // Mostrar spinner y ocultar botón
                mergeBtn.style.display = 'none';
                spinner.style.display = 'block';
                
                // Realizar solicitud al servidor para consolidar archivos
                window.location.href = `?action=merge&turno=${turno}`;
            }
        }

        // Función para editar destinatario
        function editarDestinatario(id, email) {
            document.getElementById('editDestinatarioId').value = id;
            document.getElementById('editEmail').value = email;
            document.getElementById('editDestinatarioModal').style.display = 'flex';
        }

        // Función para cerrar el modal de edición de destinatario
        function closeEditDestinatarioModal() {
            document.getElementById('editDestinatarioModal').style.display = 'none';
        }

        // Función para enviar concentrado a destinatarios
        function enviarConcentrado() {
            // Aquí puedes implementar el envío por correo electrónico
            alert('Función de envío habilitada. Aquí se implementaría el envío del archivo consolidado a la lista de destinatarios.');
        }

        // Cerrar modales al hacer clic fuera de ellos
        window.addEventListener('click', function(event) {
            const uploadModal = document.getElementById('uploadModal');
            const viewFileModal = document.getElementById('viewFileModal');
            const editDestinatarioModal = document.getElementById('editDestinatarioModal');
            
            if (event.target === uploadModal) {
                closeUploadModal();
            }
            
            if (event.target === viewFileModal) {
                closeViewFileModal();
            }
            
            if (event.target === editDestinatarioModal) {
                closeEditDestinatarioModal();
            }
        });

        // Actualizar información del turno actual
        function updateTurnInfo() {
            const now = new Date();
            const hours = now.getHours();
            const minutes = now.getMinutes();
            
            // Determinar el turno actual según la hora
            if (hours < 13 || (hours === 13 && minutes < 40)) {
                document.getElementById('currentTurn').textContent = 'Matutino';
                document.getElementById('turnTime').textContent = '07:30 AM';
                document.getElementById('nextTurnInfo').textContent = 'Próximo turno: Vespertino a las 13:40';
            } else {
                document.getElementById('currentTurn').textContent = 'Vespertino';
                document.getElementById('turnTime').textContent = '01:40 PM';
                document.getElementById('nextTurnInfo').textContent = 'Turno vespertino en curso';
            }
        }

        // Inicializar la página
        document.addEventListener('DOMContentLoaded', function() {
            updateTurnInfo();
            
            // Actualizar la hora cada minuto
            setInterval(updateTurnInfo, 60000);
            
            // Ocultar botón de subida cuando se selecciona un archivo
            const excelFile = document.getElementById('excelFile');
            if (excelFile) {
                excelFile.addEventListener('change', function() {
                    const uploadButtons = document.querySelectorAll('.upload-btn');
                    uploadButtons.forEach(btn => {
                        btn.classList.add('hidden');
                    });
                });
            }
            
            // Mostrar animación de actualización en elementos recién actualizados
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('updated')) {
                const schoolId = urlParams.get('school');
                const turno = urlParams.get('turno');
                
                if (schoolId && turno) {
                    const statusElement = document.getElementById(`status-${schoolId}-${turno}`);
                    if (statusElement) {
                        statusElement.classList.add('status-updated');
                        setTimeout(() => {
                            statusElement.classList.remove('status-updated');
                        }, 2000);
                    }
                }
            }
        });

        // Función para mostrar login (para cuando no hay sesión PHP)
        function showLogin() {
            document.getElementById('loginPage').style.display = 'flex';
        }
    </script>
</body>
</html>