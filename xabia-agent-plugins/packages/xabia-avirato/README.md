# Xabia-Avirato

Xabia-Avirato es un addon para Xabia Agent Core que conecta el asistente con el motor de reservas de Avirato. Permite consultar disponibilidad real, detectar alojamientos libres u ocupados y generar enlaces de reserva filtrados por habitación.

## Requisito

Este addon requiere tener instalado y activo:

```text
xabia-agent-core
```

## Instalación

1. En WordPress, entra en **Plugins > Añadir nuevo**.
2. Pulsa **Subir plugin**.
3. Selecciona `xabia-avirato-<versión>-retail.zip` (o el ZIP comercial del addon).
4. Instala y activa el addon.
5. Configura el agente desde **Xabia Agent**.

## Configuración

En los ajustes del agente, introduce los datos de Avirato:

| Campo | Descripción |
| --- | --- |
| ID de establecimiento | Código web de Avirato. |
| Motor de reservas | URL base del motor, normalmente `https://booking.avirato.com/`. |
| ID habitación | Opcional. Permite limitar habitaciones concretas. |
| Código promocional | Opcional. Se añade a la URL de consulta. |
| Nombre público | Nombre comercial que aparecerá en las respuestas. |
| Filtro de inclusión | Texto que deben contener los alojamientos válidos. |
| Lista de exclusión | Palabras o alojamientos que no deben mostrarse. |

El campo clave es **ID de establecimiento**. Debe contener el código web que utiliza Avirato para construir la URL del motor de reservas. Si este valor no es correcto, el addon no podrá leer disponibilidad real.

Este dato se guarda en el WordPress cliente, no en el `.env` del servidor central. Cuando el Core usa el proxy de `xabia.ai`, el addon añade al payload:

```json
{
  "avirato": {
    "establishment_id": "codigo_web_del_cliente",
    "room_filter": "filtro opcional"
  }
}
```

Si falta el ID de establecimiento y el usuario pregunta por disponibilidad, el proxy devuelve un error de configuración de Avirato.

## Funcionamiento

El addon detecta consultas de disponibilidad, calcula las fechas, consulta el motor de Avirato y responde mediante plantillas PHP. En local y en el proxy central, estas respuestas pueden resolverse como IA-Lite y no consumen tokens de IA.

El sistema verifica señales reales de disponibilidad como `numRooms` y `originalFreeRooms`. Si un alojamiento aparece en el payload pero está ocupado, no se ofrece como libre.

## Respuestas

Cuando hay disponibilidad, el bot lista opciones libres y genera una URL de reserva con solo los IDs confirmados.

Cuando el alojamiento solicitado está ocupado, el bot lo indica y propone alternativas disponibles. Si es posible, también informa de la próxima fecha disponible.

## Logs

El addon registra el estado de cada alojamiento encontrado:

```text
Casa: [Nombre] | ID: [ID] | Estado: [Libre/Ocupado]
```

## Manual

Consulta:

```text
docs/manual-usuario.md
```

## Autor

Xabia AI
