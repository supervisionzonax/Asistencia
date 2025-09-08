// Datos de usuarios (en un sistema real, esto estaría en una base de datos)
const users = {
    'admin@zonax.com': {
        password: 'admin',
        role: 'admin',
        name: 'Supervisora',
        school: 'Todas'
    },
    'secundaria6@zonax.com': {
        password: 'sec6',
        role: 'school',
        name: 'Secundaria Técnica #6',
        school: '26DST0006K'
    },
    'secundaria60@zonax.com': {
        password: 'sec60',
        role: 'school',
        name: 'Secundaria Técnica #60',
        school: '26DST0060K'
    },
    'secundaria72@zonax.com': {
        password: 'sec72',
        role: 'school',
        name: 'Secundaria Técnica #72',
        school: '26DST0072K'
    }
};

// Estado de la aplicación
let currentUser = null;
let currentTurn = 'matutino'; // 'matutino' o 'vespertino'
let uploadedFiles = {
    '26DST0006K': { matutino: null, vespertino: null },
    '26DST0060K': { matutino: null, vespertino: null },
    '26DST0072K': { matutino: null, vespertino: null }
};

// Lista de correos destinatarios
let emailRecipients = [
    'supervision@sec-sonora.edu.mx',
    'director.secundaria6@sec-sonora.edu.mx'
];

// Historial de reportes
let reportHistory = [
    { fecha: '07/09/2025', escuela: 'Secundaria Técnica #6', turno: 'Matutino', alumnos: '376/439', maestros: '14/18' },
    { fecha: '07/09/2025', escuela: 'Secundaria Técnica #60', turno: 'Matutino', alumnos: '420/500', maestros: '16/20' },
    { fecha: '07/09/2025', escuela: 'Secundaria Técnica #72', turno: 'Matutino', alumnos: '380/450', maestros: '15/19' }
];

// Inicialización
document.addEventListener('DOMContentLoaded', function() {
    // Verificar si hay una sesión activa
    const savedUser = localStorage.getItem('currentUser');
    if (savedUser) {
        currentUser = JSON.parse(savedUser);
        showDashboard();
    }

    // Cargar datos guardados
    const savedFiles = localStorage.getItem('uploadedFiles');
    if (savedFiles) {
        uploadedFiles = JSON.parse(savedFiles);
    }
    
    const savedEmails = localStorage.getItem('emailRecipients');
    if (savedEmails) {
        emailRecipients = JSON.parse(savedEmails);
    }
    
    const savedHistory = localStorage.getItem('reportHistory');
    if (savedHistory) {
        reportHistory = JSON.parse(savedHistory);
    }

    // Configurar el horario de turnos
    updateTurnInfo();

    // Cargar historial
    loadHistory();
    
    // Cargar lista de correos
    loadEmailList();

    // Event listeners
    document.getElementById('loginForm').addEventListener('submit', handleLogin);
    document.getElementById('logoutBtn').addEventListener('click', handleLogout);
    document.getElementById('uploadForm').addEventListener('submit', handleFileUpload);
    document.getElementById('mergeBtn').addEventListener('click', handleMergeFiles);
    document.getElementById('sendBtn').addEventListener('click', handleSendReport);
    document.getElementById('addEmailBtn').addEventListener('click', handleAddEmail);

    // Cerrar modales al hacer clic fuera de ellos
    window.addEventListener('click', function(event) {
        const uploadModal = document.getElementById('uploadModal');
        if (event.target === uploadModal) {
            closeUploadModal();
        }
    });
});

// Función para manejar el inicio de sesión
function handleLogin(e) {
    e.preventDefault();
    
    const email = document.getElementById('email').value;
    const password = document.getElementById('password').value;
    
    if (users[email] && users[email].password === password) {
        currentUser = {
            email: email,
            name: users[email].name,
            role: users[email].role,
            school: users[email].school
        };
        
        // Guardar en localStorage
        localStorage.setItem('currentUser', JSON.stringify(currentUser));
        
        showDashboard();
    } else {
        alert('Credenciales incorrectas. Por favor, intente nuevamente.');
    }
}

// Función para manejar el cierre de sesión
function handleLogout() {
    currentUser = null;
    localStorage.removeItem('currentUser');
    
    document.getElementById('loginPage').style.display = 'flex';
    document.getElementById('dashboardPage').style.display = 'none';
    document.getElementById('logoutBtn').style.display = 'none';
    document.getElementById('loginBtn').style.display = 'block';
    
    // Limpiar formulario
    document.getElementById('loginForm').reset();
}

// Función para mostrar el dashboard según el tipo de usuario
function showDashboard() {
    document.getElementById('loginPage').style.display = 'none';
    document.getElementById('dashboardPage').style.display = 'block';
    document.getElementById('logoutBtn').style.display = 'block';
    document.getElementById('loginBtn').style.display = 'none';
    
    // Mostrar mensaje de bienvenida
    document.getElementById('welcomeMessage').textContent = `Bienvenido/a, ${currentUser.name}`;
    
    // Configurar la interfaz según el rol
    if (currentUser.role === 'admin') {
        document.getElementById('adminPanel').style.display = 'block';
        renderAdminDashboard();
    } else {
        document.getElementById('adminPanel').style.display = 'none';
        renderSchoolDashboard();
    }
}

// Función para renderizar el dashboard de administración
function renderAdminDashboard() {
    const dashboard = document.getElementById('schoolDashboard');
    dashboard.innerHTML = '';
    
    // Agregar tarjetas para las tres escuelas
    const schools = [
        { id: '26DST0006K', name: 'Secundaria Técnica #6' },
        { id: '26DST0060K', name: 'Secundaria Técnica #60' },
        { id: '26DST0072K', name: 'Secundaria Técnica #72' }
    ];
    
    schools.forEach(school => {
        const hasFile = uploadedFiles[school.id][currentTurn] !== null;
        
        const card = document.createElement('div');
        card.className = 'card';
        card.innerHTML = `
            <div class="card-header">
                ${school.name}
            </div>
            <div class="card-body">
                <div class="school-info">
                    <div class="school-icon">
                        <i class="fas fa-school"></i>
                    </div>
                    <div class="school-details">
                        <h3>${school.id}</h3>
                        <p>Turno ${currentTurn.charAt(0).toUpperCase() + currentTurn.slice(1)}</p>
                    </div>
                </div>
                <p>Estado del reporte de hoy:</p>
                <div class="upload-status">
                    <span>Estado: <span class="status ${hasFile ? 'status-completed' : 'status-pending'}">${hasFile ? 'Completado' : 'Pendiente'}</span></span>
                    <button class="btn btn-primary" onclick="openUploadModal('${school.name}', '${school.id}')">${hasFile ? 'Reemplazar' : 'Subir'} Reporte</button>
                </div>
            </div>
        `;
        dashboard.appendChild(card);
    });
}

// Función para renderizar el dashboard de la escuela
function renderSchoolDashboard() {
    const dashboard = document.getElementById('schoolDashboard');
    dashboard.innerHTML = '';
    
    const hasFile = uploadedFiles[currentUser.school][currentTurn] !== null;
    
    const card = document.createElement('div');
    card.className = 'card';
    card.innerHTML = `
        <div class="card-header">
            ${currentUser.name}
        </div>
        <div class="card-body">
            <div class="school-info">
                <div class="school-icon">
                    <i class="fas fa-school"></i>
                </div>
                <div class="school-details">
                    <h3>${currentUser.school}</h3>
                    <p>Turno ${currentTurn.charAt(0).toUpperCase() + currentTurn.slice(1)}</p>
                </div>
            </div>
            <p>Sube el reporte de asistencia del día de hoy.</p>
            <div class="upload-status">
                <span>Estado: <span class="status ${hasFile ? 'status-completed' : 'status-pending'}">${hasFile ? 'Completado' : 'Pendiente'}</span></span>
                <button class="btn btn-primary" onclick="openUploadModal('${currentUser.name}', '${currentUser.school}')">${hasFile ? 'Reemplazar' : 'Subir'} Reporte</button>
            </div>
            <div class="upload-note">
                <p><small><i class="fas fa-info-circle"></i> Solo puedes subir un archivo por turno. Para modificarlo, contacta al administrador.</small></p>
            </div>
        </div>
    `;
    dashboard.appendChild(card);
}

// Función para actualizar la información del turno actual
function updateTurnInfo() {
    const now = new Date();
    const hours = now.getHours();
    const minutes = now.getMinutes();
    
    // Determinar el turno actual según la hora
    if (hours < 13 || (hours === 13 && minutes < 40)) {
        currentTurn = 'matutino';
        document.getElementById('currentTurn').textContent = 'Matutino';
        document.getElementById('turnTime').textContent = '07:30 AM';
        document.getElementById('nextTurnInfo').textContent = 'Próximo turno: Vespertino a las 13:40';
    } else {
        currentTurn = 'vespertino';
        document.getElementById('currentTurn').textContent = 'Vespertino';
        document.getElementById('turnTime').textContent = '01:40 PM';
        document.getElementById('nextTurnInfo').textContent = 'Turno vespertino en curso';
    }
}

// Función para abrir el modal de subida de archivos
function openUploadModal(schoolName, schoolId) {
    document.getElementById('uploadModalTitle').textContent = `Subir Reporte - ${schoolName} - Turno ${currentTurn}`;
    document.getElementById('uploadModal').style.display = 'flex';
    
    // Guardar el ID de la escuela como atributo personalizado en el formulario
    document.getElementById('uploadForm').dataset.schoolId = schoolId;
}

// Función para cerrar el modal de subida de archivos
function closeUploadModal() {
    document.getElementById('uploadModal').style.display = 'none';
    document.getElementById('uploadForm').reset();
    document.getElementById('uploadForm').removeAttribute('data-school-id');
}

// Función para manejar la subida de archivos
function handleFileUpload(e) {
    e.preventDefault();
    
    const fileInput = document.getElementById('excelFile');
    const file = fileInput.files[0];
    const schoolId = document.getElementById('uploadForm').dataset.schoolId;
    
    if (!file) {
        alert('Por favor, seleccione un archivo.');
        return;
    }
    
    // Validar que sea un archivo Excel
    const validExtensions = ['.xlsx', '.xls'];
    const fileExtension = file.name.substring(file.name.lastIndexOf('.')).toLowerCase();
    
    if (!validExtensions.includes(fileExtension)) {
        alert('Por favor, seleccione un archivo Excel válido (.xlsx o .xls).');
        return;
    }
    
    // Guardar información del archivo subido
    uploadedFiles[schoolId][currentTurn] = {
        name: file.name,
        size: file.size,
        timestamp: new Date()
    };
    
    // Guardar en localStorage
    localStorage.setItem('uploadedFiles', JSON.stringify(uploadedFiles));
    
    // Simular subida de archivo
    alert(`Archivo "${file.name}" subido correctamente para el turno ${currentTurn}.`);
    closeUploadModal();
    
    // Actualizar dashboard
    if (currentUser.role === 'admin') {
        renderAdminDashboard();
    } else {
        renderSchoolDashboard();
    }
    
    // Agregar al historial
    const schoolName = schoolId === '26DST0006K' ? 'Secundaria Técnica #6' : 
                      schoolId === '26DST0060K' ? 'Secundaria Técnica #60' : 
                      'Secundaria Técnica #72';
    
    reportHistory.unshift({
        fecha: new Date().toLocaleDateString('es-MX'),
        escuela: schoolName,
        turno: currentTurn.charAt(0).toUpperCase() + currentTurn.slice(1),
        alumnos: '--/--',
        maestros: '--/--'
    });
    
    // Guardar historial en localStorage
    localStorage.setItem('reportHistory', JSON.stringify(reportHistory));
    
    loadHistory();
}

// Función para manejar la combinación de archivos
function handleMergeFiles() {
    const mergeBtn = document.getElementById('mergeBtn');
    const spinner = document.getElementById('mergeSpinner');
    const sendBtn = document.getElementById('sendBtn');
    
    // Verificar que los 3 archivos estén subidos
    const allFilesUploaded = Object.values(uploadedFiles).every(school => 
        school[currentTurn] !== null
    );
    
    if (!allFilesUploaded) {
        alert('No se pueden juntar los archivos. Asegúrese de que las 3 escuelas hayan subido sus reportes.');
        return;
    }
    
    // Mostrar spinner y ocultar botón
    mergeBtn.style.display = 'none';
    spinner.style.display = 'block';
    
    // Simular proceso de combinación (2 segundos)
    setTimeout(() => {
        spinner.style.display = 'none';
        sendBtn.style.display = 'block';
        alert('Los archivos se han combinado exitosamente. Ahora puede enviar el reporte concentrado.');
    }, 2000);
}

// Función para manejar el envío del reporte concentrado
function handleSendReport() {
    if (emailRecipients.length === 0) {
        alert('No hay destinatarios configurados. Agregue al menos un correo electrónico.');
        return;
    }
    
    // Simular envío de correo
    alert(`Reporte concentrado enviado exitosamente a: ${emailRecipients.join(', ')}`);
    
    // Reiniciar interfaz
    document.getElementById('sendBtn').style.display = 'none';
    document.getElementById('mergeBtn').style.display = 'block';
}

// Función para manejar la adición de un nuevo correo
function handleAddEmail() {
    const newEmailInput = document.getElementById('newEmail');
    const newEmail = newEmailInput.value.trim();
    
    if (!newEmail) {
        alert('Por favor, ingrese una dirección de correo electrónico.');
        return;
    }
    
    // Validar formato de email
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(newEmail)) {
        alert('Por favor, ingrese una dirección de correo electrónico válida.');
        return;
    }
    
    // Agregar a la lista si no existe
    if (!emailRecipients.includes(newEmail)) {
        emailRecipients.push(newEmail);
        
        // Guardar en localStorage
        localStorage.setItem('emailRecipients', JSON.stringify(emailRecipients));
        
        // Actualizar interfaz
        loadEmailList();
        newEmailInput.value = '';
        
        alert('Correo electrónico agregado exitosamente.');
    } else {
        alert('Este correo electrónico ya está en la lista.');
    }
}

// Función para editar un correo
function editEmail(email) {
    const newEmail = prompt('Editar correo electrónico:', email);
    if (newEmail && newEmail !== email) {
        const index = emailRecipients.indexOf(email);
        if (index !== -1) {
            emailRecipients[index] = newEmail;
            
            // Guardar en localStorage
            localStorage.setItem('emailRecipients', JSON.stringify(emailRecipients));
            
            alert('Correo electrónico actualizado. Recargando lista...');
            loadEmailList();
        }
    }
}

// Función para eliminar un correo
function deleteEmail(email) {
    if (confirm(`¿Está seguro de que desea eliminar el correo ${email}?`)) {
        const index = emailRecipients.indexOf(email);
        if (index !== -1) {
            emailRecipients.splice(index, 1);
            
            // Guardar en localStorage
            localStorage.setItem('emailRecipients', JSON.stringify(emailRecipients));
            
            alert('Correo electrónico eliminado. Recargando lista...');
            loadEmailList();
        }
    }
}

// Función para cargar la lista de correos
function loadEmailList() {
    const emailList = document.getElementById('emailList');
    emailList.innerHTML = '';
    
    emailRecipients.forEach(email => {
        const emailItem = document.createElement('div');
        emailItem.className = 'email-item';
        emailItem.innerHTML = `
            <span>${email}</span>
            <div class="email-actions">
                <button class="btn-icon" onclick="editEmail('${email}')"><i class="fas fa-edit"></i></button>
                <button class="btn-icon" onclick="deleteEmail('${email}')"><i class="fas fa-trash"></i></button>
            </div>
        `;
        emailList.appendChild(emailItem);
    });
}

// Función para cargar el historial
function loadHistory() {
    const historyTableBody = document.getElementById('historyTableBody');
    historyTableBody.innerHTML = '';
    
    reportHistory.forEach(report => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${report.fecha}</td>
            <td>${report.escuela}</td>
            <td>${report.turno}</td>
            <td>${report.alumnos}</td>
            <td>${report.maestros}</td>
            <td><button class="btn-icon"><i class="fas fa-download"></i></button></td>
        `;
        historyTableBody.appendChild(row);
    });
}