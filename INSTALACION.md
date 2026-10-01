# Alta de una web en el hub

**Subir, activar y ya está.** No hay tercer paso.

1. Ajustes → Plugins → Añadir nuevo → Subir plugin → `dist/marionaagency-dev-0.2.0.zip`
2. Activar.

Al activarse, el plugin hace solo lo que antes había que hacer a mano:

- genera su token y lo entrega al hub (la URL y el secreto viajan dentro del zip);
- se llama a sí mismo para comprobar si la cabecera `Authorization` sobrevive a ese servidor;
- si no sobrevive —Apache con CGI/FastCGI, Plesk detrás de nginx: el caso normal, no la excepción—
  repara el `.htaccess` él mismo, comprueba que la reparación funciona y que la web sigue en pie,
  y revierte si algo no cuadra;
- copia la configuración del hub a la base de datos, para no depender de un fichero que cualquier
  actualización puede reemplazar.

En Ajustes → MarionaAgency Dev, la fila **Puente** dice en qué estado ha quedado:

| Estado | Qué significa | Qué hacer |
|---|---|---|
| `ok` | La cabecera llega sola. | Nada. |
| `repaired` | Hizo falta reparar el `.htaccess` y funcionó. | Nada. |
| `pending` | Se quedó a medias por tiempo. | Nada: se retoma sola al entrar en el escritorio. |
| `unknown` | La web no puede llamarse a sí misma (loopback bloqueado). | Comprobar desde fuera con el `curl` de abajo. |
| `manual` | Ni el `.htaccess` lo arregla. | Proxy de Plesk o WAF. Mirar el servidor. |

Comprobación desde fuera, sin entrar en la web:

```
curl -H "Authorization: Bearer prueba" -H "X-MAD-Auth-Probe: 1" \
     https://DOMINIO/wp-json/mad/v1/diagnose
```

`auth_header_received: true` = puente operativo.

## Si la web está detrás de Cloudflare (naranja)

Regla WAF de tipo *Skip* para `/wp-json/mad/*` y sin *Rate limiting* en esa ruta.
El propio `diagnose` lo avisa cuando detecta Cloudflare.

## Generar el paquete

```
./build.sh                # dist/marionaagency-dev-<v>.zip           con la config de agencia
./build.sh --sin-config   # dist/marionaagency-dev-<v>-sin-config.zip
```

`agencia.env` (no se versiona) guarda `MAD_HUB_URL`, `MAD_HUB_SECRET` y `MAD_GITHUB_TOKEN`.

**El zip con config lleva el secreto de la agencia dentro.** Nunca se publica en una URL
accesible ni se manda por correo. Para actualizar webs que ya lo tienen, usar el zip
`--sin-config`: el plugin conserva la configuración que ya tenía.

## Actualizar una web sin entrar en su escritorio

Mientras el canal de actualización automática no esté montado, se despliega con un script de
un solo uso que se autoborra: descarga el paquete `--sin-config`, lo copia sobre el plugin y
ejecuta la rutina de activación. Probado el 03/09/2026 en mariona-agency.com. Dos minutos por web.
