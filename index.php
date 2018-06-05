<?php


$srcdir = dirname(__FILE__);
require_once("$srcdir/Decompiler4.class.php");

$file = $argv[1];
decode("$srcdir/$file");
function decode($path){
    $dc = new Decompiler(array("php"));
    $dc->decompileFile($path);
    //ob_start();
    $dc->output();
    $string = ob_get_contents();
    //file_put_contents($path, $string);
    //flush();

}
?>
