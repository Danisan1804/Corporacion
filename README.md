Corporación LEL · Plataforma de gestión formativa

## Sobre el proyecto

Este proyecto es una plataforma web que desarrollé para apoyar la gestión del programa formativo de la **Corporación LEL — Laboratorio de Emprendimiento y Liderazgo**, ubicada en Caucasia, Antioquia.

La idea principal es organizar en un solo lugar la convocatoria, las solicitudes de inscripción, la selección de participantes, la ruta formativa, los encuentros, la asistencia, las evidencias y los informes del programa.

La plataforma está pensada para que LEL pueda pasar de procesos dispersos en formularios, archivos y mensajes a un flujo digital con información trazable y conectada a una base de datos.

> El proyecto se encuentra en etapa de MVP: ya cuenta con la estructura funcional principal, pero debe seguir probándose y endureciéndose antes de considerarse un sistema de producción de alta exigencia.

## Demo

- Página pública: [corporacion-lel.22web.org](https://corporacion-lel.22web.org/)
- Panel administrativo: `/admin/admin.html`
- Portal del participante: `/participant/`

Las credenciales de administración y de participantes no se publican en este repositorio.

## Problema que resuelvo

LEL desarrolla procesos de formación en liderazgo, emprendimiento, participación y construcción de paz. Antes de digitalizar el proceso, parte de la información podía quedar repartida entre formularios, archivos y conversaciones.

Con esta plataforma busco resolver principalmente:

- Falta de trazabilidad sobre cada solicitud y participante.
- Dificultad para saber quién está en cada módulo.
- Registro manual de asistencia y evidencias.
- Poca visibilidad del avance de la ruta formativa.
- Tiempo elevado para preparar informes y revisar indicadores.
- Riesgo de duplicar o perder información entre diferentes archivos.

## Cómo funciona el sistema

El flujo general que diseñé es el siguiente:

```text
Página pública
      ↓
Solicitud de inscripción
      ↓
Revisión del administrador
      ├── Rechazada → queda registrada con el motivo
      └── Aprobada → se crea el participante y sus credenciales
                              ↓
                    Portal del participante
                              ↓
          Evidencias + asistencia + avance por módulos
                              ↓
              Indicadores, seguimiento e informes
```

## Funcionalidades

### 1. Página pública

La página principal presenta a LEL y orienta a las personas interesadas en participar.

Incluye:

- Identidad visual de la Corporación LEL.
- Información sobre su propósito y líneas de trabajo.
- Secciones sobre participación ciudadana, construcción de paz, liderazgo y emprendimiento.
- Proceso explicado en tres pasos: escuchar, formar y activar.
- Formulario para enviar una solicitud de inscripción.
- Diseño adaptable a computadores, tabletas y celulares.
- Menú móvil, animaciones sutiles y soporte para tema claro u oscuro del dispositivo.
- Metadatos básicos para compartir y mejorar la presentación en buscadores.

Archivos principales:

- `index.html`: estructura y contenido de la página.
- `style.css`: identidad visual, responsive design y tema claro/oscuro.
- `script.js`: formulario, menú móvil y animaciones de entrada.
- `assets/logo-lel.jpg`: logo utilizado en la interfaz y favicon.

### 2. Solicitudes de inscripción

Las personas interesadas envían sus datos desde la página pública. La solicitud no convierte automáticamente a la persona en participante.

El administrador debe revisar la información y decidir individualmente si la solicitud es aprobada o rechazada.

El flujo contempla:

- Estado de revisión.
- Aprobación o rechazo manual.
- Registro del motivo de rechazo.
- Evitar que una solicitud repetida se procese como una cuenta nueva sin revisión.

### 3. Panel administrativo

El panel permite gestionar el programa desde diferentes apartados:

- **Resumen:** indicadores generales de la cohorte.
- **Participantes:** listado, estado, módulo actual y trazabilidad.
- **Solicitudes:** revisión de solicitudes pendientes.
- **Formación:** creación de encuentros y seguimiento de asistencia.
- **Informes:** consulta de indicadores del piloto.
- **Equipo del proyecto:** distribución de roles sugeridos para el equipo de trabajo.

El administrador también puede crear encuentros formativos y relacionarlos con un módulo de la ruta.

### 4. Portal del participante

Cada participante aprobado puede ingresar con sus propias credenciales.

Desde el portal puede:

- Consultar su módulo actual.
- Ver su estado dentro del programa.
- Consultar su porcentaje general de asistencia.
- Registrar el nombre y la descripción de una evidencia.
- Adjuntar una foto como soporte de asistencia.
- Consultar los encuentros disponibles.
- Ver el estado de los cinco módulos de la ruta formativa.
- Consultar las evidencias que ya ha registrado.

### 5. Ruta formativa

La ruta actual está organizada en cinco módulos:

1. Liderazgo personal y comunitario.
2. Pensamiento emprendedor.
3. Diseño y validación de proyectos.
4. Marketing y transformación digital.
5. Gestión, sostenibilidad y cierre.

El estado de cada módulo se calcula a partir de los encuentros registrados:

- **Pendiente:** todavía no ha comenzado.
- **En curso:** ya inició al menos un encuentro.
- **Completado:** todos los encuentros del módulo ya finalizaron.

### 6. Asistencia y evidencias

La asistencia se registra por encuentro y participante. Para registrar asistencia se solicita una evidencia fotográfica.

El backend valida:

- Que exista una sesión válida de participante.
- Que el encuentro corresponda al participante.
- Que se envíe una imagen válida.
- Que la imagen no supere el límite configurado de 5 MB.
- Que el archivo sea JPG, PNG o WEBP.
- Que no se pueda registrar asistencia fuera de la ventana definida.

La asistencia general se recalcula a partir de los encuentros cerrados y se guarda en la base de datos.

## Arquitectura del proyecto

```text
INS LEL/
├── index.html                  # Página pública
├── style.css                   # Estilos públicos
├── script.js                   # Interacciones públicas
├── assets/
│   └── logo-lel.jpg            # Identidad visual
├── admin/
│   ├── admin.html              # Panel administrativo
│   ├── admin-panel.js          # Lógica del panel
│   ├── admin-login.js          # Inicio de sesión administrativo
│   ├── admin-login.css         # Estilos del login
│   ├── admin.css               # Estilos generales del panel
│   ├── api.php                 # API y reglas del sistema
│   ├── config.php              # Configuración privada de MySQL
│   ├── informes.php            # Informes protegidos
│   ├── solicitudes.php         # Vista de solicitudes
└── participant/
    ├── index.html              # Portal del participante
    ├── participant.js          # Lógica del portal
    └── participant.css         # Estilos del portal
```

## Tecnologías utilizadas

- HTML5 semántico.
- CSS3 con variables, media queries y diseño responsive.
- JavaScript nativo, sin frameworks obligatorios.
- PHP para el backend y la API.
- PDO para la conexión con MySQL.
- MySQL/MariaDB para almacenar solicitudes, participantes, encuentros, asistencia y evidencias.
- Sesiones PHP para separar el acceso administrativo del acceso de participantes.

## Instalación local

### Requisitos

- PHP 8 o superior.
- MySQL o MariaDB.
- Servidor local como XAMPP, Laragon o similar.
- Extensión PDO MySQL habilitada.
- Extensión `fileinfo` habilitada para validar imágenes.

### Pasos

1. Clonar el repositorio:

   ```bash
   git clone github.com/Danisan1804/Corporaci-n
   cd INS-LEL
   ```

2. Copiar `admin/config.example.php` como `admin/config.local.php`. Este archivo está ignorado por Git y no debe subirse al repositorio.

3. Completar las credenciales de la base de datos en `admin/config.local.php`:

   ```php
   return [
       'host' => 'localhost',
       'db' => 'lel_db',
       'user' => 'root',
       'pass' => '',
       'charset' => 'utf8mb4'
   ];
   ```

4. Crear/importar la base de datos y las tablas del sistema según el esquema SQL definido para la instalación. En esta versión del repositorio todavía debe añadirse el archivo `.sql` versionado.

5. Colocar el proyecto dentro de la carpeta pública del servidor local.

6. Abrir la página pública desde el navegador.

7. Crear el primer administrador mediante un procedimiento privado del hosting o directamente en la base de datos. El repositorio no incluye un instalador público de administradores.

## Configuración para Servidor

La configuración se realiza directamente en `admin/config.php` con los datos entregados por el hosting:

```php
return [
    'host' => 'HOST_MYSQL',
    'db' => 'NOMBRE_DE_BASE_DE_DATOS',
    'user' => 'USUARIO_MYSQL',
    'pass' => 'CONTRASENA_MYSQL',
    'charset' => 'utf8mb4'
];
```


## Seguridad aplicada

En el backend implementé varias medidas básicas:

- Consultas preparadas mediante PDO.
- Validación y limpieza inicial de datos recibidos.
- Sesiones separadas para administradores y participantes.
- Comprobación de autorización antes de consultar o modificar información administrativa.
- Validación MIME y tamaño de las imágenes cargadas.
- Nombres aleatorios para archivos de evidencia.
- Respuestas JSON sin almacenar credenciales en el frontend.
- Encabezados de no-cache para respuestas sensibles.

Estas medidas reducen riesgos frecuentes, pero no sustituyen una auditoría de seguridad, HTTPS, protección CSRF, políticas de contraseñas robustas, rate limiting del servidor y copias de seguridad.

## Pruebas recomendadas antes de producción

- Probar solicitudes duplicadas con el mismo correo.
- Probar aprobación y rechazo de solicitudes.
- Probar cierre de sesión y expiración de sesión.
- Probar acceso directo a endpoints sin autenticación.
- Probar asistencia antes, durante y después de la ventana permitida.
- Intentar cargar archivos que no sean imágenes.
- Probar imágenes mayores a 5 MB.
- Verificar que los datos se actualicen correctamente en MySQL.
- Revisar la interfaz en Chrome, Edge, Firefox y Safari.
- Revisar tamaños móviles de 360 px, 390 px, 768 px y escritorio.
- Crear copias de seguridad antes de actualizar la base de datos.

## Estado actual y próximos pasos

La plataforma ya tiene el flujo principal del MVP. Los siguientes pasos que considero importantes son:

- Incorporar el esquema SQL versionado dentro del repositorio.
- Separar las credenciales mediante variables de entorno cuando el hosting lo permita.
- Añadir protección CSRF a los formularios administrativos.
- Aplicar rate limiting persistente en todos los endpoints sensibles.
- Configurar las cookies de sesión con `HttpOnly`, `Secure` y `SameSite`.
- Bloquear la ejecución y el listado de archivos dentro de `admin/uploads/`.
- Añadir recuperación segura de contraseña por correo.
- Incorporar auditoría de acciones administrativas.
- Añadir pruebas automatizadas para la API.
- Configurar copias de seguridad periódicas.
- Revisar permisos de las carpetas de carga y bloquear la ejecución de scripts dentro de ellas.
- Migrar a un hosting con soporte completo de HTTPS, backups y control de servidor si el sistema pasa a producción.

## Convenciones de trabajo

Para mantener el proyecto ordenado, utilizo estas convenciones:

- Los nombres de archivos se mantienen en minúsculas y con guiones cuando sea necesario.
- La lógica de cada área permanece separada: público, administrador y participante.
- Los cambios de base de datos deben documentarse antes de desplegarse.
- No se guardan contraseñas, tokens ni datos de conexión dentro del repositorio.
- Cada cambio funcional debe probarse en los tres flujos: público, administrador y participante.


## Autor

Este proyecto lo diseñé y desarrollé como una propuesta de solución digital para apoyar el trabajo de la Corporación LEL y facilitar el seguimiento de sus participantes, encuentros y resultados.

Daniel Sánchez, Corporación LEL, Agost-Sept 2026.
