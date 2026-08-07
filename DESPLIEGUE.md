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
   ```

   **`DEBUG` no se define.** Si está, los errores de base se muestran al
   público.

5. En **Settings → Networking → Generate Domain** sale la URL pública.

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

## Cuando cambie el padrón

El archivo de julio 2026 es una foto. Para actualizarlo se regenera el SQL
desde el Excel nuevo y se vuelve a cargar la tabla `padron`. Las
inscripciones no se tocan: viven en otra tabla y guardan copia de los datos
que la persona declaró al anotarse.
