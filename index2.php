<?php

require_once("$srcdir/Decompiler3.class.php");
$dc = new Decompiler(array("php"));
$file = $argv[1];
$dc->decompileFile("$file");
if($dc != null){
    $dc->output();
}
