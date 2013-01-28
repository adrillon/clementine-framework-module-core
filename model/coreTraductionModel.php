<?php
/**
 * coreTraductionModel : fonctions de traduction du template et des contenus
 * 
 * @package 
 * @version $id$
 * @copyright 
 * @author Pierre-Alexis <pa@quai13.com> 
 * @license 
 */
class coreTraductionModel extends coreTraductionModel_Parent
{

    /**
     * Function : traduire() 
     * Traduit un texte - d'un bloc par exemple - dans la langue en cours
     * Recupere la traduction dans la table clementine_traduction
     *  CREATE TABLE IF NOT EXISTS `clementine_traduction` (`str` varchar(255) NOT NULL, `lang` enum('fr','en') NOT NULL DEFAULT 'fr', `texte` text, PRIMARY KEY (`str`,`lang`));
     */
    function traduire($text) 
    {
        $db = $this->getModel('db');
        $request = $this->getRequest();
        $sql  = 'SELECT texte FROM clementine_traduction ';
        $sql .= 'WHERE lang = \'' .  $request->LANG . '\' ';
        $sql .= 'AND str = \'' . $db->escape_string($text) . '\' ';
        $sql .= 'LIMIT 1';
        $stmt = $db->query($sql);
        if (!$db->num_rows($stmt)) {
            $sql  = 'SELECT texte FROM clementine_traduction ';
            $sql .= 'WHERE lang = \'' . __DEFAULT_LANG__ . '\' ';
            $sql .= 'AND str = \'' . $db->escape_string($text) . '\' ';
            $sql .= 'LIMIT 1';
            $stmt = $db->query($sql);
            if (!$db->num_rows($stmt)) {
                return $text;
            }
        }
        $traduction = $db->fetch_array($stmt);
        $traduction['texte'] = str_replace('__CLEMENTINE_CONTENUS_WWW_ROOT__' ,__WWW_ROOT__ , $traduction['texte']);
        $traduction['texte'] = str_replace('__CLEMENTINE_CONTENUS_WWW__' ,__WWW__ , $traduction['texte']);
        return $traduction['texte'];
    }

    /**
     * Function : traduire_contenu() 
     * Traduit un contenu - provenant de la base de données par exemple - dans la langue en cours
     * Recupere la traduction dans la table clementine_traduction_contenu
     *  CREATE TABLE IF NOT EXISTS `clementine_traduction_contenu` (`orig_table` varchar(255) NOT NULL, `orig_field` varchar(255) NOT NULL, `orig_id` int(11) NOT NULL, `lang` enum('en') NOT NULL DEFAULT 'en', `texte` text, PRIMARY KEY (`orig_table`,`orig_field`,`orig_id`,`lang`));
     */
    function traduire_contenu($orig_table, $orig_field, $orig_id) 
    {
        $request = $this->getRequest();
        $db = $this->getModel('db');
        $sql  = 'SELECT texte FROM clementine_traduction_contenu ';
        $sql .= 'WHERE lang = \'' . $db->escape_string($request->LANG) . '\' ';
        $sql .= 'AND orig_table = \'' . $db->escape_string($orig_table) . '\' ';
        $sql .= 'AND orig_field = \'' . $db->escape_string($orig_field) . '\' ';
        $sql .= 'AND orig_id = \'' . $db->escape_string($orig_id) . '\' ';
        $sql .= 'LIMIT 1';
        $stmt = $db->query($sql);
        $traduction = $db->fetch_array($stmt);
        if ($db->num_rows($stmt)) {
            return $traduction['texte'];
        } else {
            return 'NULL';
        }
    }

}

?>
