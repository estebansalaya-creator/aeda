# Inscripción — Día del Niño

Formulario público de inscripción al evento, validado contra el padrón de afiliados.

## Cómo funciona

Son dos pasos en una sola página:

1. La persona ingresa el **DNI del titular**. Se busca en `padron` y se cuentan
   sus hijos menores de la edad tope a la fecha del evento.
2. Si tiene al menos uno, aparece el formulario con los datos ya cargados desde
   el padrón —nombre, empresa, teléfono, mail— para que sólo confirme o corrija,
   y elija cuántos adultos y cuántos chicos van.

Si el documento no está en el padrón, o está pero como familiar, o el grupo no
tiene chicos de la edad, el formulario no se muestra y se explica por qué.

Con los datos de julio 2026: **567 grupos familiares** tienen hijos menores de
16, y son **787 chicos**.

## Desplegar en Railway

1. Subí esta carpeta a un repositorio y creá el servicio desde ahí. Railway
   detecta PHP por el `composer.json` y arranca con el `Procfile`.
2. Agregá al proyecto un servicio **MySQL** y enlazalo. Railway inyecta solo
   `MYSQL_URL` (o `MYSQLHOST` / `MYSQLUSER` / `MYSQLPASSWORD` / `MYSQLDATABASE`);
   `db.php` acepta cualquiera de las dos formas.
3. Cargá en esa base el padrón y la tabla de inscripciones:

   ```
   mysql < sql/padron_aeda_julio2026.sql
   mysql < sql/01_inscripciones.sql
   ```

4. Configurá las variables:

   | Variable | Para qué | Valor sugerido |
   |---|---|---|
   | `FECHA_EVENTO` | Fecha con la que se calcula la edad | `2026-08-16` |
   | `EDAD_TOPE` | Tope sin incluir: 16 = hasta 15 cumplidos | `16` |
   | `TOKEN_LISTADO` | Clave para ver los inscriptos | algo largo y al azar |
   | `LUGAR_EVENTO` | Sale en el mail de confirmación | opcional |

## Mail de confirmación

Se manda por SMTP, hablando el protocolo directo sobre un socket — sin
PHPMailer ni composer install, que en Railway es una fuente de problemas al
desplegar por una funcionalidad chica. Soporta puerto 587 con STARTTLS y 465
con SSL.

| Variable | Para qué |
|---|---|
| `SMTP_HOST` | `smtp.gmail.com` para Gmail o Workspace |
| `SMTP_PORT` | `587` (STARTTLS) o `465` (SSL) |
| `SMTP_USER` | La casilla que envía |
| `SMTP_PASS` | **Contraseña de aplicación**, no la clave de la cuenta |
| `SMTP_DESDE` | Remitente; por defecto igual que `SMTP_USER` |
| `SMTP_NOMBRE` | Nombre que se ve como remitente |
| `SMTP_COPIA` | Copia oculta al organizador, opcional |

Con Gmail o Google Workspace la clave normal no sirve: hay que activar la
verificación en dos pasos y generar una contraseña de aplicación en
myaccount.google.com/apppasswords.

Si las variables están vacías no se manda nada y el formulario funciona
igual. Y si el envío falla, **la inscripción no se pierde**: ya está guardada
antes de intentar el mail, el error va al log y la persona ve su
confirmación en pantalla igual.

## Ver los inscriptos

```
/listado.php?t=EL_TOKEN
/listado.php?t=EL_TOKEN&csv=1
```

Totales arriba y descarga a CSV. El token es lo único que lo protege: **no es
un login**. Alcanza para un evento, pero si esto va a durar hay que ponerle
usuarios de verdad.

## Cosas para tener en cuenta

- **Una inscripción por documento.** El DNI tiene índice único; si alguien se
  anota de nuevo, se actualizan los datos en vez de duplicar la fila.
- **Los datos se copian del padrón a la inscripción a propósito.** El padrón se
  reemplaza mes a mes y la inscripción tiene que conservar lo que la persona
  declaró el día que se anotó.
- **El formulario expone nombre y empresa a quien acierte un DNI.** Es el precio
  de no pedir clave. Si te preocupa, la vuelta más simple es pedir también la
  fecha de nacimiento del titular como segundo dato.
- Los chicos que se listan salen del padrón, así que si una familia tuvo un hijo
  después del último envío no va a aparecer hasta que se actualice.
