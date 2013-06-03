Clementine Framework module Core
================================

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

La gestion des erreurs
---
rapports d'erreurs, envoi par mail
