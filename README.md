Clementine Framework : module CORE
===

Clementine : MVC et héritage
---

* ```$this->getModel()``` et ```$this->getHelper()```

* ```$this->getController()``` et utilité de ```$this->data```

* ```$this->getBlock()``` ou ```$this->getBlockHtml()```
recoit dans ```$data``` le tableau ```$this->data``` peuplé par le controleur
recoit dans ```$request``` l'objet ```ClementineRequest```

La configuration
---
Dans les fichiers ```config.ini```, qui se surchargent dans l'ordre des overrides.

L'héritage dans Clémentine
---
* le principe des overrides : calques à la Photoshop

* modules découplés => 

```php
parent::indexAction($request, $params = null);
```

* spécificité pour les blocks 

```php
$this->getParentBlock();
```

L'adoption
---
Héritage de modules entiers par le fichier ```config.ini```

ClementineRequest
---
```php
$this->getRequest()
$request->get('int', 'id_user'); // get, post, cookie, session, request...
$request->map_url() // et $request->canonical_url()
```
Note : il est mieux d'utiliser $request->GET plutôt que $_GET.

Fonctionnalités pour le debug
---

Rapports d'erreur
===

Lorsqu'une erreur PHP est détectée par le framework, il génère un rapport d'erreur contenant des informations sur l'erreur elle même, un aperçu du code qui l'a causée, et des informations sur la requête, la configuration du serveur et du client, une backtrace.

Options de debug
===

Les principales options de debug sont les suivantes, on les active dans la section `[clementine_debug]` du `config.ini` :

**enabled** : activer le mode debug (performances moindres...). 
_Valeurs :_ `[0,1]`

**allowed_ip** : adresses IP autorisées à voir les erreurs si `display_errors` est activé. 
_Liste d'adresses IP séparé par des virgules_

**display_errors** : active l'affichage des erreurs
_Valeurs :_ `[0,1]`

**send_errors_by_email** : active l'envoi d'erreurs par email.
_Valeurs :_ `[0,1]`

Le destinataire est celui défini dans 
```ini
[clementine_global]
email_dev=
```

**send_errors_by_email_max** : nombre max de mails d'erreur à envoyer par requête HTTP
_Valeurs :_ `nombre entier`

**log_errors** : log les erreurs avec `error_log()`
_Valeurs :_ `[0,1]`

**error_log** : chemin vers le fichier de log.
_Valeurs :_ `/path/to/writable/file.log`

Suivi des logs, exemple avec les requêtes SQL
===
Le module [db](https://github.com/pa-de-solminihac/clementine-framework-module-db) permet de logguer toutes les requêtes SQL.
