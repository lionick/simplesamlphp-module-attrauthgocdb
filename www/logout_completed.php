<?php

$globalConfig = SimpleSAML_Configuration::getInstance();
$t = new SimpleSAML_XHTML_Template($globalConfig, 'attrauthgocdb:logout_completed.tpl.php');
$t->show();
