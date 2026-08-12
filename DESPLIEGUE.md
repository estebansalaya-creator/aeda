# Subir a GitHub y desplegar en Railway

Los comandos van desde `C:\aeda\evento_dia_del_nino`.

## 1. Repositorio local

```powershell
cd C:\aeda\evento_dia_del_nino
git init
git add .
git status          # <- MIRAR ESTO ANTES DE SEGUIR
```

En `git status` **no tienen que aparecer** `config.local.php`,
`probar_mail.php` ni `sql/padron_aeda_julio2026.sql`. Si alguno aparece, el
`.gitignore` no se aplicó: paralo ahí y revisá antes de commitear, porque
después queda en el historial aunque lo borres.

```powershell
git commit -m "Formulario de inscripcion al Dia de la Ninez"
git branch -M main
```

## 2. GitHub

Con la CLI de GitHub, si la tenés:

```powershell
gh repo create aeda-dia-ninez --private --source=. --push
```

Sin la CLI: creá el repositorio vacío desde github.com (privado, **sin**
README ni .gitignore, que ya los tenemos) y después:

```powershell
git remote add origin https://github.com/USUARIO/aeda-dia-ninez.git
git push -u origin main
```

## 3. Railway

1. **New Project → Deploy from GitHub repo** y elegí el repositorio.
   Railway detecta PHP por el `composer.json` y arranca con el `Procfile`.
2. En el mismo proyecto: **New → Database → MySQL**.
3. Entrá al servicio de la aplicación, pestaña **Variables**, y agregá una
   referencia a la base: `MYSQL_URL` → `${{MySQL.MYSQL_URL}}`.
   Con eso `db.php` se conecta solo.
4. Agregá el resto de las variables:

   ```
   FECHA_EVENTO   2026-09-19
   HORA_EVENTO    de 10 a 18:30 hs
   LUGAR_EVENTO   Predio SITTAN — Av. España 2500, CABA
   EDAD_TOPE      16
   TOKEN_LISTADO  (algo largo y al azar)
   SMTP_HOST      smtp.gmail.com
   SMTP_PORT      465
   SMTP_USER      informes@aeda.com.ar
   SMTP_PASS      (la contraseña de aplicación, sin espacios)
   SMTP_NOMBRE    AEDA
   SMTP_COPIA     (opcional, copia oculta al organizador)
   REPORTE_PARA   (destinatarios del reporte diario, separados por coma)
   ```

   **`DEBUG` no se define.** Si está, los errores de base se muestran al
   público.

5. En **Settings → Networking → Generate Domain** sale la URL pública.

## 3 bis. El reporte diario por mail

La planilla diaria la manda `reporte.php`. En Railway se resuelve con un
**segundo servicio**, apuntando al mismo repositorio, que en vez de quedar
escuchando corre el script y termina.

1. **New → GitHub Repo** y elegí el mismo repositorio. Nombralo `reporte`
   para no confundirlo con la aplicación web.
2. En **Settings → Deploy**:
   - **Custom Start Command**: `php reporte.php`
   - **Cron Schedule**: `0 9 * * *`
   - **Serverless / Sleep**: activado, así entre corrida y corrida no consume.
3. En **Variables**, las mismas que la aplicación (conviene usar
   `${{nombre-del-servicio-web.VARIABLE}}` para no cargarlas dos veces) más:

   ```
   REPORTE_PARA    quien.recibe@aeda.com.ar, otro@aeda.com.ar
   REPORTE_ASUNTO  Inscripciones Día de la Niñez     (opcional)
   ```

   El servicio necesita sí o sí `MYSQL_URL` y todas las `SMTP_*`.

**El cron de Railway va en UTC.** Argentina está tres horas atrás, así que
`0 9 * * *` es **6 de la mañana** acá. Para que llegue a las 8, poné
`0 11 * * *`.

Antes de esperar al cron, probá el envío entrando a
`https://LA-URL/reporte.php?t=EL_TOKEN`: contesta en texto plano si mandó el
mail o qué falló.

## 4. Cargar el padrón en la base de Railway

El SQL no está en el repositorio a propósito. Copiá desde la pestaña
**Variables** del servicio MySQL los datos de conexión pública
(`MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`) y desde DBeaver
creá una conexión nueva con esos datos. Después abrí y ejecutá:

```
sql/padron_aeda_julio2026.sql
sql/01_inscripciones.sql
```

## 5. Comprobar

- La URL pública tiene que mostrar el formulario.
- Probá con el documento **38530847** (5 chicos): tiene que dejarte inscribir.
- Revisá el listado en `https://LA-URL/listado.php?t=EL_TOKEN`.
- Verificá que llegue el mail de confirmación.
- Dispará el reporte a mano en `https://LA-URL/reporte.php?t=EL_TOKEN` y
  fijate que llegue la planilla adjunta.

## Cuando cambie el padrón

El archivo de julio 2026 es una foto. Para actualizarlo se regenera el SQL
desde el Excel nuevo y se vuelve a cargar la tabla `padron`. Las
inscripciones no se tocan: viven en otra tabla y guardan copia de los datos
que la persona declaró al anotarse.
