# MarionaAgency Dev

Puente entre Claude y las webs WordPress de la agencia. Instalas el plugin en
una web y esa web aparece sola en Claude: sin dar de alta un MCP por sitio.

```
┌─────────────┐        ┌──────────────────┐        ┌────────────────────┐
│  claude.ai  │──MCP──▶│   Hub (Worker)   │──REST─▶│  50 × WordPress    │
│ Claude Code │        │  registro cifrado│        │  plugin instalado  │
└─────────────┘        └──────────────────┘        └────────────────────┘
       │                                                      ▲
       └──────────── Skill + curl (sin hub) ──────────────────┘
```

Dos caminos hacia la misma API:

- **Hub** — una sola conexión MCP en claude.ai. Vale desde web y móvil.
- **Skill** — Claude Code habla directo con cada web por `curl`. Sin infraestructura.

## Por qué no se desconecta

La conexión anterior se caía porque MCP sobre HTTP mantiene una sesión viva
contra cada web. Aquí no hay sesión: cada llamada es una petición REST
independiente. Si una web tarda o devuelve un 502, el hub reintenta con espera
creciente y sigue; las demás webs ni se enteran.

## Instalación

### 1. El plugin, en cada web

Sube la carpeta `marionaagency-dev/` a `wp-content/plugins/` y actívala. En
`wp-config.php` de cada web, antes de `/* That's all */`:

```php
define( 'MAD_HUB_URL',    'https://mad-hub.TU-SUBDOMINIO.workers.dev' );
define( 'MAD_HUB_SECRET', 'el-secreto-de-agencia' );
```

Al activarse, la web se registra sola en el hub. Nada más que hacer.

Ve a **Ajustes → MarionaAgency Dev** para ver el estado, generar tokens y
consultar la auditoría.

### 2. El hub, una sola vez

```bash
cd hub
npm install
npx wrangler kv namespace create SITES     # pega el id en wrangler.toml
npx wrangler secret put AGENCY_SECRET      # el mismo de wp-config.php
npx wrangler secret put MCP_ACCESS_KEY     # irá en la URL del conector
npx wrangler secret put TOKEN_KEY          # cifra los tokens en KV
npx wrangler deploy
```

Genera secretos largos con `openssl rand -hex 32`.

### 3. Conectar Claude

En **claude.ai → Ajustes → Conectores → Añadir conector personalizado**:

```
https://mad-hub.TU-SUBDOMINIO.workers.dev/mcp/TU_MCP_ACCESS_KEY
```

Para Claude Code, copia `skill/SKILL.md` a
`~/.claude/skills/marionaagency-dev/SKILL.md` y crea `~/.mad/sites.json`
(formato en la propia skill, permisos `600`).

## Seguridad

| Medida | Qué protege |
|---|---|
| Tokens hasheados (SHA-256) | Quien lea la BD no obtiene credenciales usables |
| Scopes por token | `read`, `content`, `content:raw`, `files`, `db`, `db:write`, `admin` |
| Escritura verificada | Cada escritura de contenido se relee; si WordPress (KSES) eliminó algo, se deshace y responde error |
| HTTPS obligatorio | Rechaza cualquier petición en claro |
| Firma HMAC en el alta | Nadie inscribe una web ajena en el hub |
| Anti-replay (10 min) | Una petición capturada no se puede reutilizar |
| Tokens cifrados en KV | AES-GCM; el hub no los guarda en claro |
| Jaula en `wp-content` | `wp-config.php`, `wp-admin` y `wp-includes` fuera de alcance |
| Backup + lint previo | Un PHP con error de sintaxis se rechaza, no se guarda |
| SQL restringido | `DROP`/`ALTER`/`TRUNCATE` prohibidos; `WHERE` obligatorio |
| Auditoría completa | Quién, qué, cuándo y con qué resultado |
| Límite de peticiones | 120/min por token, configurable |
| Interruptor de emergencia | `define( 'MAD_DISABLED', true );` corta todo |

Lo que el plugin **no** hace, a propósito: ejecutar PHP arbitrario enviado por
petición, tocar `wp-config.php`, cambiar el estado de pedidos de WooCommerce,
ni editarse a sí mismo.

## Hostings

**Cloudflare** — crea una regla WAF de tipo *Skip* para `/wp-json/mad/*` y
excluye esa ruta del rate limiting.

**Plesk con ModSecurity** — el hub manda el contenido de los ficheros en base64
automáticamente, así que las reglas OWASP no lo rechazan. Si aun así falla,
excluye `/wp-json/mad/` de las reglas del dominio.

**Cabecera `Authorization` perdida** (Apache + CGI) — al `.htaccess`:

```apache
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

Ante cualquier duda: `GET /wp-json/mad/v1/diagnose` sin token dice qué pasa y
cómo arreglarlo.

## Actualizar las 50 webs

Publica una release en el repo privado de GitHub y cada web la verá como una
actualización normal. Para repos privados, en `wp-config.php`:

```php
define( 'MAD_GITHUB_TOKEN', 'ghp_...' );  // PAT de solo lectura
```

Ajusta la constante `REPO` en `includes/class-mad-updater.php`.

## Empaquetar

```bash
./build.sh
```

Genera `dist/marionaagency-dev-<version>.zip`, listo para subir a WordPress o
adjuntar a una release.
