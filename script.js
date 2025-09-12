// Variables globales
let currentViewingFile = null;
let currentFileName = null;
let currentSchoolId = null;
let currentTurno = null;
let currentFileId = null;

// Variables de usuario desde PHP
const userSchoolName = '<?php echo isset($_SESSION["user"]) ? $_SESSION["user"]["nombre"] : ""; ?>';
const isUserAdmin = '<?php echo isset($_SESSION["user"]) && $_SESSION["user"]["rol"] === "admin" ? "true" : "false"; ?>';

// Función para abrir el modal de subida de archivos
function openUploadModal(turno, schoolId = null) {
    let title = "Subir Reporte - ";
    
    if (isUserAdmin === 'true' && schoolId) {
        // Para admin, mostrar a qué escuela está subiendo
        const schoolNames = {
            '26DST0006K': 'Secundaria Técnica #6',
            '26DST0060K': 'Secundaria Técnica #60', 
            '26DST0072K': 'Secundaria Técnica #72'
        };
        title += schoolNames[schoolId] + " - Turno " + turno;
    } else {
        title += userSchoolName + " - Turno " + turno;
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
    const deleteBtn = document.getElementById('deleteFileBtn');
    
    if (isUserAdmin === 'true' && fileId) {
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