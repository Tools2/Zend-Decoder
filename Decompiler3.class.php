<?php
define('INDENT', "\t");
ini_set('error_reporting', E_ALL);

$_CURRENT_FILE = NULL;

function color($str, $color = 33) {
    return "\x1B[{$color}m$str\x1B[0m";
}

function str($code, $indent = '') // {{{
{
    if (is_array($code)) {
        $array = array();
        foreach ($code as $key => $value) {
            $array[$key] = str($value, $indent);
        }
        return $array;
    }
    if (is_object($code)) {
        $code = foldToCode($code, $indent);
        return $code->toCode($indent);
    }

    return (string) $code;
}

// }}}
function foldToCode($src, $indent = '') // {{{ wrap or rewrap anything to Decompiler_Code
{
    if (is_array($indent)) {
        $indent = $indent['indent'];
    }

    if (!is_object($src)) {
        return new Decompiler_Code($src);
    }

    if (!method_exists($src, 'toCode')) {
        var_dump($src);
        exit('no toCode');
    }
    if (get_class($src) != 'Decompiler_Code') {
        // rewrap it
        $src = new Decompiler_Code($src->toCode($indent));
    }

    return $src;
}

// }}}
function value($value,$noescape = false) // {{{
{
    $spec = xcache_get_special_value($value);
    if (isset($spec)) {
        $value = $spec;
        if (!is_array($value)) {
            // constant
            return $value;
        }
    }

    if (is_a($value, 'Decompiler_Object')) {
        // use as is
    }
    else {
        if (is_array($value)) {
            $value = new Decompiler_ConstArray($value);
        }
        else {
            $value = new Decompiler_Value($value,$noescape);
        }
    }
    return $value;
}

// }}}
function unquoteName_($str, $asVariableName, $indent = '') // {{{
{
    $str2 = str($str, $indent);
    if (preg_match("!^\"[\\w_][\\w\\d_\\\\]*\"\$!", $str2)) {
        return str_replace('\\\\', '\\', substr($str2, 1, -1));
    }
    else {
        if ($asVariableName) {
            // убирает скобки в $v->{$a}
            if (!preg_match("!^\\$[\\w_][\\w\\d_\\\\]*\$!", $str2)) {
                return "{" . $str2 . "}";
            } else {
                return $str2;
            }
        }
        else {
            return $str2;
        }
    }
}

// }}}
function unquoteVariableName($str, $indent = '') // {{{
{
    return unquoteName_($str, TRUE, $indent);
}

// }}}
function unquoteName($str, $indent = '') // {{{
{
    return unquoteName_($str, FALSE, $indent);
}

// deobfuscate function name
function fixFunctionName($name,$addprefix = true) {
    static $dict = array();
    if (is_object($name)) return $name;
    if (strpos($name, '\\') !== FALSE) {
        preg_match('#^(.*)\\\\(.+)$#',$name,$r);
        list (,$ns,$var) = $r;
//      $ns = strtok($name, '\\');
//      $len = strlen($ns);
//      $var = substr($name, $len);
    } else  $var = $name;
    if (!preg_match("!^[a-zA-Z_][a-zA-Z0-9_]*$!",$var))
    {
        if (isset($dict[$name])) return $dict[$name];
        else {
            $dict[$name] = (isset($ns) ? $ns.'\\' : '').($addprefix ? '_obfuscate_' : '').base62_encode($var);
            return $dict[$name];
        }
    }
    return $name;
}

function bchexdec($hex)
{
    $dec = 0;
    $len = strlen($hex);
    for ($i = 1; $i <= $len; $i++) {
        $dec = bcadd($dec, bcmul(strval(hexdec($hex[$i - 1])), bcpow('16', strval($len - $i))));
    }
    return $dec;
}

function base62_encode ($val,$base62_vals =  "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789")
{
    bcscale(0);
    $val = bchexdec(bin2hex(strval($val)));
    $s = '';
    do {
        $v = bcmod($val,62);
        $s = $base62_vals[$v].$s;
        $val = bcdiv(bcsub($val,$v), 62);
    } while (bccomp($val,0) == 1);
    return $s;
}

// }}}
class Decompiler_Object // {{{
{
}

// }}}

function removeSlashQuotes($str) { return str_replace(array('[\"','\"]'),array('["','"]'),$str[1]); }

class Decompiler_Value extends Decompiler_Object // {{{
{
    var $value;
    var $noescape;

    function Decompiler_Value($value = NULL,$noescape = FALSE) {
        $this->value = $value;
        $this->noescape = $noescape;
    }


    function toCode($indent) {
        if (gettype($this->value) == 'string') {
            if ($GLOBALS['_CURRENT_FILE'] && $this->value == $GLOBALS['_CURRENT_FILE']) return '__FILE__';
            if ($GLOBALS['_CURRENT_FILE'] && $this->value == dirname($GLOBALS['_CURRENT_FILE'])) return '__DIR__';

            if ($this->noescape) {
                $v = addcslashes($this->value,"\\\0..\37\"");
                $v = preg_replace_callback("#({\\\$.+?})#","removeSlashQuotes", $v);
                return '"'.$v.'"';
            } else {
                // TODO: для PHP 5.4 нужно еще эранирвоать \e
                // print ("#".$this->value."#\n");
                // addcslashes добавляет вместо \x8 - \b, который не понимает PHP
                $v = str_replace('\b',"\\010",addcslashes(str_replace("\\","@@--SLASH--@@",$this->value),"\t\n\v\f\r\"\0..\37\$"));
                $v = preg_replace('#\\\\\$([^\\w{_])#','$\1',$v);
                // print ("%".$v."%\n");
                $v = str_replace("@@--SLASH--@@@@--SLASH--@@","@@--SLASH--@@\\\\",$v);
                $v = preg_replace('#@@--SLASH--@@$#',"\\\\\\\\",$v);
                $v = preg_replace('#@@--SLASH--@@([$tnvfrx0\\\\])#',"\\\\\\\\$1",$v);
                $v = str_replace("@@--SLASH--@@","\\",$v);
                $v = preg_replace_callback("#({\\\$.+?})#","removeSlashQuotes", $v);
                //  print ("~".$v."~\n");
                return '"'.$v.'"';
            }

        } else {
            return var_export($this->value, TRUE);
        }
    }
}

// }}}
class Decompiler_Code extends Decompiler_Object // {{{
{
    var $src;

    function Decompiler_Code($src) {
        assert('isset($src)');
        $this->src = $src;
    }

    function toCode($indent) {
        return $this->src;
    }
}

// }}}
class Decompiler_Binop extends Decompiler_Code // {{{
{
    var $opc;
    var $op1;
    var $op2;
    var $parent;
    var $res;

    function Decompiler_Binop($parent, $op1, $opc, $op2) {
        $this->parent = & $parent;
        $this->opc = $opc;
        $this->op1 = $op1;
        $this->op2 = $op2;
        $this->res = NULL;
    }

    function toCode($indent) {
        if ($this->res) return $this->res;
        $opstr = $this->parent->binops[$this->opc];
        if (is_a($this->op1, 'Decompiler_TriOp') || is_a($this->op1, 'Decompiler_Binop') && $this->op1->opc != $this->opc) {
            $op1 = "(" . str($this->op1, $indent) . ")";
        }
        else {
            $op1 = $this->op1;
        }

        if (is_a($this->op2, 'Decompiler_TriOp') || is_a($this->op2, 'Decompiler_Binop') && $this->op2->opc != $this->opc && substr($opstr, -1) != '=') {
            $op2 = "(" . str($this->op2, $indent) . ")";
        }
        else {
            $op2 = $this->op2;
        }

        if (str($op1) == '0' && ($this->opc == XC_ADD || $this->opc == XC_SUB)) {
            return $opstr . str($op2, $indent);
        }

        $sop1 = str($op1,$indent);
        $sop2 = str($op2,$indent);

        // склейка разбитых на части строк в одну
        if ($this->opc == XC_CONCAT && substr($sop1,-1) == '"' && $sop2[0] == '"') {
            $sop1 = substr($sop1,0,-1);
            $sop2 = substr($sop2,1);
            // экранирование переменных в строках
            if (preg_match("#^([^\\\\]*?)(\\$[\\w_][\\w\\d_\\\\]*$|\\$[\\w_][\\w\\d_\\\\]*->[\\w_][\\w\\d_]*$)#",$sop1,$pr)
                && preg_match("#[\\w\\d_\\\\]#",$sop2[0])) $sop1 = $pr[1].'{'.$pr[2].'}';
            $this->res = $sop1.$sop2;
        } else {
            $this->res = $sop1 . ' ' . $opstr . ($this->opc == XC_ASSIGN_REF ? '' : ' ') . $sop2;
        }
        return $this->res;
    }
}

// }}}
class Decompiler_TriOp extends Decompiler_Code // {{{
{
    var $condition;
    var $trueValue;
    var $falseValue;
    var $res;

    function Decompiler_TriOp($condition, $trueValue, $falseValue) {
        $this->condition = $condition;
        $this->trueValue = $trueValue;
        $this->falseValue = $falseValue;
        $this->res = NULL;
    }

    function toCode($indent) {
        if ($this->res) return $this->res;
        $trueValue = $this->trueValue;
        if (is_a($this->trueValue, 'Decompiler_TriOp')) {
            $trueValue = "(" . str($trueValue, $indent) . ")";
        }
        $falseValue = $this->falseValue;
        if (is_a($this->falseValue, 'Decompiler_TriOp')) {
            $falseValue = "(" . str($falseValue, $indent) . ")";
        }

        return $this->res = str($this->condition) . ' ? ' . str($trueValue) . ' : ' . str($falseValue);
    }
}

// }}}
class Decompiler_Fetch extends Decompiler_Code // {{{
{
    var $src;
    var $fetchType;
    var $reference;
    var $silent;

    function Decompiler_Fetch($src, $type, $globalsrc, $reference = false, $silent = false) {
        $this->src = $src;
        $this->fetchType = $type;
        $this->globalsrc = $globalsrc;
        $this->reference = $reference;
        $this->silent = $silent;
    }

    function toCode($indent) {
        $res = '';
        switch ($this->fetchType) {
            case XC_FETCH_PROPERTY:
                return str($this->reference, '->', $this->src);

            case ZEND_FETCH_STATIC_MEMBER:
                return str($this->reference, '::$', $this->src);
            case ZEND_FETCH_LOCAL:
                $res = str($this->src);
                if ($res[0] == '"') {
                    // fix for ${"asss$qq"} = 1
                    if($this->reference) $res = unquoteVariableName($res);
                    elseif ($this->silent) $res = '$' . unquoteName($res);
                }
                break;
            case ZEND_FETCH_STATIC:
                if (ZEND_ENGINE_2_3) {
                    // closure local variable?
                    $res = str($this->src);
                    break;
                }
                die('static fetch cant to string');
            case ZEND_FETCH_GLOBAL:
            case ZEND_FETCH_GLOBAL_LOCK:
                $res = $this->globalsrc;
                break;
            default:
                var_dump($this->fetchType);
                assert(0);
        }
        if ($this->reference) return "$".$res;
        return $res;
    }
}

// }}}
class Decompiler_Box // {{{
{
    var $obj;

    function Decompiler_Box(&$obj) {
        $this->obj = & $obj;
    }

    function toCode($indent) {
        return $this->obj->toCode($indent);
    }

    function toObject($parent,$assigncmd = XC_ASSIGN) {
        if (method_exists($this->obj,'toObject')) return $this->obj->toObject($parent,$assigncmd);
        else return $this->obj->toCode('');
    }
}

// }}}
class Decompiler_Dim extends Decompiler_Value // {{{
{
    var $offsets = array();
    var $isLast = FALSE;
    var $isObject = FALSE;
    var $assign = NULL;

    function toCode($indent) {
        if (is_a($this->value, 'Decompiler_ListBox')) {
            $exp = str($this->value->obj->src, $indent);
        }
        else {
            $exp = str($this->value, $indent);
        }
        $last = count($this->offsets) - 1;
        foreach ($this->offsets as $i => $dim) {
            if ($this->isObject && $i == $last) {
                $exp .= '->' . unquoteVariableName($dim, $indent);
            }
            else {
                if (is_a($dim,'Decompiler_ListBox') && $dim == $this->value) {
                    echo "ERROR: Recursive ListBox.\n";
                    print_r($this);
                    assert(0);
                    //exit;
                    continue;
                }
                $exp .= '[' . str($dim, $indent) . ']';
            }
        }
        return $exp;
    }
}

// }}}
class Decompiler_DimBox extends Decompiler_Box // {{{
{
}

// }}}
class Decompiler_List extends Decompiler_Code // {{{
{
    var $src;
    var $dims = array();
    var $everLocked = FALSE;

    function toCode($indent,$asobject = false, &$parent = null,$assigncmd = XC_ASSIGN_REF) {
        if (count($this->dims) == 1 && !$this->everLocked) {
            $dim = $this->dims[0];
            unset($dim->value);
            $dim->value = $this->src;
            if (!isset($dim->assign)) {
                if ($asobject) return $dim;
                else return str($dim, $indent);
            }
            if ($asobject) return new Decompiler_Binop($parent,$this->dims[0]->assign,$assigncmd, $dim);
            else return str($this->dims[0]->assign, $indent) . ' = ' . str($dim, $indent);
        }
        /* flatten dims */
        $assigns = array();
        foreach ($this->dims as $dim) {
            $assign = & $assigns;
            foreach ($dim->offsets as $offset) {
                $assign = & $assign[str($offset)];
            }
            $assign = str($dim->assign, $indent);
        }
        if ($asobject) return new Decompiler_Binop($parent,$this->toList($assigns),$assigncmd, $this->src);
        else return str($this->toList($assigns)) . ' = ' . str($this->src, $indent);
    }

    function toList($assigns) {
        $keys = array_keys($assigns);
        if (count($keys) < 2) {
            $keys[] = 0;
        }
        $max = call_user_func_array('max', $keys);
        $list = 'list(';
        for ($i = 0; $i <= $max; $i++) {
            if ($i) {
                $list .= ', ';
            }
            if (!isset($assigns[$i])) {
                continue;
            }
            if (is_array($assigns[$i])) {
                $list .= $this->toList($assigns[$i]);
            }
            else {
                $list .= $assigns[$i];
            }
        }
        return $list . ')';
    }

    function toObject($parent,$assigncmd = XC_ASSIGN) {
        return $this->toCode('',true,$parent,$assigncmd);
    }

}

// }}}
class Decompiler_ListBox extends Decompiler_Box // {{{
{
}

// }}}
class Decompiler_Array extends Decompiler_Value // {{{
{
    // emenets
    function Decompiler_Array() {
        $this->value = array();
    }

    function toCode($indent) {
        $subindent = $indent . INDENT;

        $elementsCode = array();
        $index = 0;
        foreach ($this->value as $element) {
            list($key, $value) = $element;
            if (!isset($key)) {
                $key = $index++;
            }
            $elementsCode[] = array(str($key, $subindent), str($value, $subindent), $key, $value);
        }

        $exp = "array(";
        $indent = $indent . INDENT;
        $assocWidth = 0;
        $multiline = 0;
        $i = 0;
        foreach ($elementsCode as $element) {
            list($keyCode, $valueCode) = $element;
            if ((string) $i !== $keyCode) {
                $assocWidth = 1;
                break;
            }
            ++$i;
        }
        foreach ($elementsCode as $element) {
            list($keyCode, $valueCode, $key, $value) = $element;
            if ($assocWidth) {
                $len = strlen($keyCode);
                if ($assocWidth < $len) {
                    $assocWidth = $len;
                }
            }
            if (is_array($value) || is_a($value, 'Decompiler_Array')) {
                $multiline++;
            }
        }

        $i = 0;
        foreach ($elementsCode as $element) {
            list($keyCode, $value) = $element;
            if ($multiline) {
                if ($i) {
                    $exp .= ",";
                }
                $exp .= "\n";
                $exp .= $indent;
            }
            else {
                if ($i) {
                    $exp .= ", ";
                }
            }

            if ($assocWidth) {
                if ($multiline) {
                    $exp .= sprintf("%-{$assocWidth}s => ", $keyCode);
                }
                else {
                    $exp .= $keyCode . ' => ';
                }
            }

            $exp .= $value;

            $i++;
        }
        if ($multiline) {
            $exp .= "\n$indent)";
        }
        else {
            $exp .= ")";
        }
        return $exp;
    }
}

// }}}
class Decompiler_ConstArray extends Decompiler_Array // {{{
{
    function Decompiler_ConstArray($array) {
        $elements = array();
        foreach ($array as $key => $value) {
            $elements[] = array(value($key), value($value));
        }
        $this->value = $elements;
    }
}

// }}}
class Decompiler_ForeachBox extends Decompiler_Box // {{{
{
    var $iskey;

    function toCode($indent) {
        return 'foreach (' . '';
    }
}

// }}}

class Decompiler {
    var $namespace;
    var $namespaceDecided;

    function Decompiler() {
        // {{{ testing
        // XC_UNDEF XC_OP_DATA
        $this->test = !empty($_ENV['XCACHE_DECOMPILER_TEST']);
        $this->usedOps = array();

        if ($this->test) {
            $content = file_get_contents(__FILE__);
            for ($i = 0; $opname = xcache_get_opcode($i); $i++) {
                if (!preg_match("/\\bXC_" . $opname . "\\b(?!')/", $content)) {
                    echo "not recognized opcode ", $opname, "\n";
                }
            }
        }
        // }}}
        // {{{ opinfo
        $this->unaryops = array(
            XC_BW_NOT   => '~',
            XC_BOOL_NOT => '!',
            XC_ADD      => "+",
            XC_SUB      => "-",
            XC_NEW      => "new ",
            XC_THROW    => "throw ",
            XC_CLONE    => "clone ",
        );
        $this->binops = array(
            XC_ADD => "+",
            XC_ASSIGN_ADD => "+=",
            XC_SUB => "-",
            XC_ASSIGN_SUB => "-=",
            XC_MUL => "*",
            XC_ASSIGN_MUL => "*=",
            XC_DIV => "/",
            XC_ASSIGN_DIV => "/=",
            XC_MOD => "%",
            XC_ASSIGN_MOD => "%=",
            XC_SL => "<<",
            XC_ASSIGN_SL => "<<=",
            XC_SR => ">>",
            XC_ASSIGN_SR => ">>=",
            XC_CONCAT => ".",
            XC_ASSIGN_CONCAT => ".=",
            XC_POW                 => "**",
            XC_ASSIGN_POW          => "*=",
            XC_IS_IDENTICAL => "===",
            XC_IS_NOT_IDENTICAL => "!==",
            XC_IS_EQUAL => "==",
            XC_IS_NOT_EQUAL => "!=",
            XC_IS_SMALLER => "<",
            XC_IS_SMALLER_OR_EQUAL => "<=",
            XC_BW_OR => "|",
            XC_ASSIGN_BW_OR => "|=",
            XC_BW_AND => "&",
            XC_ASSIGN_BW_AND => "&=",
            XC_BW_XOR => "^",
            XC_ASSIGN_BW_XOR => "^=",
            XC_BOOL_XOR => "xor",
            XC_ASSIGN => "=",
            XC_ASSIGN_DIM          => "=",
            XC_ASSIGN_OBJ          => "=",
            XC_ASSIGN_REF          => "= &",
            XC_JMP_SET             => "?:",
            XC_JMP_SET_VAR         => "?:",
            XC_JMPZ_EX             => "&&",
            XC_JMPNZ_EX            => "||",
            XC_INSTANCEOF          => "instanceof",
        );
        if (defined('IS_CONSTANT_AST')) {
            $this->binops[ZEND_BOOL_AND] = '&&';
            $this->binops[ZEND_BOOL_OR]  = '||';
        }
        // }}}
        $this->includeTypes = array( // {{{
            ZEND_EVAL => 'eval',
            ZEND_INCLUDE => 'include',
            ZEND_INCLUDE_ONCE => 'include_once',
            ZEND_REQUIRE => 'require',
            ZEND_REQUIRE_ONCE => 'require_once',
        );
        // }}}
    }

    function detectNamespace($name) // {{{
    {

        // TODO: проверить корректность смены пространства имен для констант и переменных, идущих после функций и классов
        if (strpos($name, '\\') !== FALSE) {
            $ns = strtok($name, '\\');
            if ($ns != $this->namespace) {
                $this->namespace = $ns;
                echo 'namespace ', $this->namespace, ";\n\n";
            } else return;
        }
        $this->namespaceDecided = TRUE;
    }

    // }}}
    function stripNamespace($name) // {{{
    {
        $len = strlen($this->namespace) + 1;
        if (substr($name, 0, $len) == $this->namespace . '\\') {
            return substr($name, $len);
        }
        else {
            return $name;
        }
    }

    // }}}
    function outputPhp(&$EX, $range) // {{{
    {
        $needBlankline = isset($EX['lastBlock']);
        $indent = $EX['indent'];
        $curticks = 0;
        for ($i = $range[0]; $i <= $range[1]; $i++) {
            $op = $EX['opcodes'][$i];
            if (isset($op['gofrom'])) {
                if ($needBlankline) {
                    $needBlankline = FALSE;
                    echo PHP_EOL;
                }
                echo 'label' . $i, ":\n";
            }
            if (isset($op['php'])) {
                $toticks = isset($op['ticks']) ? (int) str($op['ticks']) : 0;
                if ($curticks != $toticks) {
                    $oldticks = $curticks;
                    $curticks = $toticks;
                    if (!$curticks) {
                        echo $EX['indent'], "}\n\n";
                        $indent = $EX['indent'];
                    }
                    else {
                        if ($oldticks) {
                            echo $EX['indent'], "}\n\n";
                        }
                        else {
                            if (!$oldticks) {
                                $indent .= INDENT;
                            }
                        }
                        if ($needBlankline) {
                            $needBlankline = FALSE;
                            echo PHP_EOL;
                        }
                        echo $EX['indent'], "declare (ticks=$curticks) {\n";
                    }
                }
                if ($needBlankline) {
                    $needBlankline = FALSE;
                    echo PHP_EOL;
                }
                echo $indent, str($op['php'], $indent), ";\n";
                $EX['lastBlock'] = 'basic';
            }
        }
        if ($curticks) {
            echo $EX['indent'], "}\n";
        }
    }

    // }}}
    function getOpVal($op, &$EX, $free = FALSE,$noescape = FALSE) // {{{
    {
        switch ($op['op_type']) {
            case XC_IS_CONST:
                return value($op['constant'],$noescape);

            case XC_IS_VAR:
            case XC_IS_TMP_VAR:
                $T = & $EX['Ts'];
                @$ret = $T[$op['var']];
                if ($free && empty($this->keepTs)) {
                    unset($T[$op['var']]);
                }
                return $ret;

            case XC_IS_CV:
                $var = $op['var'];
                $var = $EX['op_array']['vars'][$var];
                return '$' . $var['name'];

            case XC_IS_UNUSED:
                if (isset($op['EA.type']) && $op['EA.type'] == ZEND_FETCH_CLASS_GLOBAL && $op['var'] && $op['var'] ==  $op['opline_num'] && $op['op_num'] == 1) return '$this';
                return NULL;
        }
    }

    // }}}
    function removeKeyPrefix($array, $prefix) // {{{
    {
        $prefixLen = strlen($prefix);
        $ret = array();
        foreach ($array as $key => $value) {
            if (substr($key, 0, $prefixLen) == $prefix) {
                $key = substr($key, $prefixLen);
            }
            $ret[$key] = $value;
        }
        return $ret;
    }

    // }}}
    function &fixOpcode($opcodes, $removeTailing = FALSE, $defaultReturnValue = NULL) // {{{
    {
        $last = count($opcodes) - 1;
        for ($i = 0; $i <= $last; $i++) {

            //  clean unknown opcodes
            //  if (in_array($opcodes[$i]['opcode'],array(200,205))) $opcodes[$i]['opcode'] = XC_NOP;

            if (function_exists('xcache_get_fixed_opcode')) {
                $opcodes[$i]['opcode'] = xcache_get_fixed_opcode($opcodes[$i]['opcode'], $i);
            }
            if (isset($opcodes[$i]['op1'])) {
                $opcodes[$i]['op1'] = $this->removeKeyPrefix($opcodes[$i]['op1'], 'u.');
                $opcodes[$i]['op1']['op_num'] = 1;
                $opcodes[$i]['op2'] = $this->removeKeyPrefix($opcodes[$i]['op2'], 'u.');
                $opcodes[$i]['op2']['op_num'] = 2;
                $opcodes[$i]['result'] = $this->removeKeyPrefix($opcodes[$i]['result'], 'u.');
                $opcodes[$i]['result']['op_num'] = 3;
            }
            else {
                $op = array(
                    'op1' => array(),
                    'op2' => array(),
                    'op3' => array(),
                );
                foreach ($opcodes[$i] as $name => $value) {
                    if (preg_match('!^(op1|op2|result)\\.(.*)!', $name, $m)) {
                        list(, $which, $field) = $m;
                        $op[$which][$field] = $value;
                    }
                    else {
                        if (preg_match('!^(op1|op2|result)_type$!', $name, $m)) {
                            list(, $which) = $m;
                            $op[$which]['op_type'] = $value;
                        }
                        else {
                            $op[$name] = $value;
                        }
                    }
                }
                $opcodes[$i] = $op;
            }
        }

        if ($removeTailing) {
            $last = count($opcodes) - 1;
            if ($opcodes[$last]['opcode'] == XC_HANDLE_EXCEPTION) {
                $this->usedOps[XC_HANDLE_EXCEPTION] = true;
                $opcodes[$last]['opcode'] = XC_NOP;
                --$last;
            }
            if ($opcodes[$last]['opcode'] == XC_RETURN
                || $opcodes[$last]['opcode'] == XC_GENERATOR_RETURN) {
                $op1 = $opcodes[$last]['op1'];
                if ($op1['op_type'] == XC_IS_CONST && array_key_exists('constant', $op1) && $op1['constant'] === $defaultReturnValue) {
                    $opcodes[$last]['opcode'] = XC_NOP;
                    --$last;
                }
            }
        }
        return $opcodes;
    }

    // }}}
    function decompileBasicBlock(&$EX, $range, $unhandled = FALSE) // {{{
    {
        $this->dasmBasicBlock($EX, $range);
        if ($unhandled) {
            $this->dumpRange($EX, $range);
        }
        $this->outputPhp($EX, $range);
    }

    // }}}
    function isIfCondition(&$EX, $range) // {{{
    {
        $opcodes = & $EX['opcodes'];
        $firstOp = & $opcodes[$range[0]];
        return $firstOp['opcode'] == XC_JMPZ && !empty($firstOp['jmpouts']) && $opcodes[$firstOp['jmpouts'][0] - 1]['opcode'] == XC_JMP
            && !empty($opcodes[$firstOp['jmpouts'][0] - 1]['jmpouts'])
            && $opcodes[$firstOp['jmpouts'][0] - 1]['jmpouts'][0] == $range[1] + 1;
    }

    // }}}
    function removeJmpInfo(&$EX, $line) // {{{
    {
        $opcodes = & $EX['opcodes'];
        if (!isset($opcodes[$line]['jmpouts'])) return;
        foreach ($opcodes[$line]['jmpouts'] as $jmpTo) {
            $jmpins = & $opcodes[$jmpTo]['jmpins'];
            $jmpins = array_flip($jmpins);
            unset($jmpins[$line]);
            $jmpins = array_keys($jmpins);
        }
        // $opcodes[$line]['opcode'] = XC_NOP;
        unset($opcodes[$line]['jmpouts']);
    }

    // }}}
    function beginScope(&$EX, $doIndent = TRUE) // {{{
    {
        array_push($EX['scopeStack'], array($EX['lastBlock'], $EX['indent']));
        if ($doIndent) {
            $EX['indent'] .= INDENT;
        }
        $EX['lastBlock'] = NULL;
    }

    // }}}
    function endScope(&$EX) // {{{
    {
        list($EX['lastBlock'], $EX['indent']) = array_pop($EX['scopeStack']);
    }

    // }}}
    function beginComplexBlock(&$EX) // {{{
    {
        if (isset($EX['lastBlock'])) {
            echo PHP_EOL;
            $EX['lastBlock'] = NULL;
        }
    }

    // }}}
    function endComplexBlock(&$EX) // {{{
    {
        $EX['lastBlock'] = 'complex';
    }

    // }}}
    function decompileComplexBlock(&$EX, $range) // {{{
    {
        $T = & $EX['Ts'];
        $opcodes = & $EX['opcodes'];
        $indent = $EX['indent'];

        $firstOp = & $opcodes[$range[0]];
        $lastOp = & $opcodes[$range[1]];

        // {{{ && || and or
        if (($firstOp['opcode'] == XC_JMPZ_EX || $firstOp['opcode'] == XC_JMPNZ_EX) && !empty($firstOp['jmpouts'])
            && $firstOp['jmpouts'][0] == $range[1] + 1
            && $lastOp['opcode'] == XC_BOOL
            && $firstOp['opcode']['result']['var'] == $lastOp['opcode']['result']['var']
        ) {
            $this->removeJmpInfo($EX, $range[0]);

            $this->recognizeAndDecompileClosedBlocks($EX, array($range[0], $range[0]));
            $op1 = $this->getOpVal($firstOp['result'], $EX, TRUE);

            $this->recognizeAndDecompileClosedBlocks($EX, array($range[0] + 1, $range[1]));
            $op2 = $this->getOpVal($lastOp['result'], $EX, TRUE);

            $T[$firstOp['result']['var']] = new Decompiler_Binop($this, $op1, $firstOp['opcode'], $op2);
            return FALSE;
        }
        // }}}
        // {{{ ?: excluding JMP_SET/JMP_SET_VAR
        if ($firstOp['opcode'] == XC_JMPZ && !empty($firstOp['jmpouts'])
            && $range[1] >= $range[0] + 3
            && ($opcodes[$firstOp['jmpouts'][0] - 2]['opcode'] == XC_QM_ASSIGN || $opcodes[$firstOp['jmpouts'][0] - 2]['opcode'] == XC_QM_ASSIGN_VAR)
            && $opcodes[$firstOp['jmpouts'][0] - 1]['opcode'] == XC_JMP && $opcodes[$firstOp['jmpouts'][0] - 1]['jmpouts'][0] == $range[1] + 1
            && ($lastOp['opcode'] == XC_QM_ASSIGN || $lastOp['opcode'] == XC_QM_ASSIGN_VAR)
        ) {
            $trueRange = array($range[0] + 1, $firstOp['jmpouts'][0] - 2);
            $falseRange = array($firstOp['jmpouts'][0], $range[1]);
            $this->removeJmpInfo($EX, $range[0]);

            $condition = $this->getOpVal($firstOp['op1'], $EX);
            $this->recognizeAndDecompileClosedBlocks($EX, $trueRange);
            $trueValue = $this->getOpVal($opcodes[$trueRange[1]]['result'], $EX, true);
            $this->recognizeAndDecompileClosedBlocks($EX, $falseRange);
            $falseValue = $this->getOpVal($opcodes[$falseRange[1]]['result'], $EX, true);
            $T[$opcodes[$trueRange[1]]['result']['var']] = new Decompiler_TriOp($condition, $trueValue, $falseValue);
            return false;
        }
        // }}}
        // {{{ goto
        if ($firstOp['opcode'] == XC_JMP && !empty($firstOp['jmpouts']) && $firstOp['jmpouts'][0] == $range[1] + 1) {
            $this->removeJmpInfo($EX, $range[0]);
            $firstOp['opcode'] = XC_GOTO;
            $target = $firstOp['op1']['var'];
            $firstOp['goto'] = $target;
            $opcodes[$target]['gofrom'][] = $range[0];

            $this->recognizeAndDecompileClosedBlocks($EX, $range);
            return FALSE;
        }
        // }}}
        // {{{ for
        if (!empty($firstOp['jmpins']) && $opcodes[$firstOp['jmpins'][0]]['opcode'] == XC_JMP
            && $lastOp['opcode'] == XC_JMP && !empty($lastOp['jmpouts']) && $lastOp['jmpouts'][0] <= $firstOp['jmpins'][0]
            && !empty($opcodes[$range[1] + 1]['jmpins']) && $opcodes[$opcodes[$range[1] + 1]['jmpins'][0]]['opcode'] == XC_JMPZNZ
        ) {
            $nextRange = array($lastOp['jmpouts'][0], $firstOp['jmpins'][0]);
            $conditionRange = array($range[0], $nextRange[0] - 1);
            $this->removeJmpInfo($EX, $conditionRange[1]);
            $bodyRange = array($nextRange[1], $range[1]);
            $this->removeJmpInfo($EX, $bodyRange[1]);

            $initial = '';
            if (isset($EX['init_for'])) {
                $initial = &$EX['init_for'];
                unset ($EX['init_for']);
            }

            $this->beginScope($EX);
            $this->dasmBasicBlock($EX, $conditionRange);
            $conditionCodes = array();
            for ($i = $conditionRange[0]; $i <= $conditionRange[1]; ++$i) {
                if (isset($opcodes[$i]['php'])) {
                    $conditionCodes[] = str($opcodes[$i]['php'], $EX);
                }
            }
            $conditionCodes[] = str($this->getOpVal($opcodes[$conditionRange[1]]['op1'], $EX), $EX);
            if (implode(',', $conditionCodes) == 'true') {
                $conditionCodes = array();
            }
            $this->endScope($EX);

            $this->beginScope($EX);
            $this->dasmBasicBlock($EX, $nextRange);
            $nextCodes = array();
            for ($i = $nextRange[0]; $i <= $nextRange[1]; ++$i) {
                if (isset($opcodes[$i]['php'])) {
                    $nextCodes[] = str($opcodes[$i]['php'], $EX);
                }
            }
            $this->endScope($EX);

            $this->beginComplexBlock($EX);
            echo $indent, 'for (', str($initial, $EX), '; ', implode(', ', $conditionCodes), '; ', implode(', ', $nextCodes), ') ', '{', PHP_EOL;
            $this->beginScope($EX);
            $this->recognizeAndDecompileClosedBlocks($EX, $bodyRange);
            $this->endScope($EX);
            echo $indent, '}', PHP_EOL;
            $this->endComplexBlock($EX);
            return;
        }
        // }}}
        // {{{ if/elseif/else
        if ($this->isIfCondition($EX, $range)) {
            $this->beginComplexBlock($EX);
            $isElseIf = FALSE;
            do {
                $ifRange = array($range[0], $opcodes[$range[0]]['jmpouts'][0] - 1);
                $this->removeJmpInfo($EX, $ifRange[0]);
                $this->removeJmpInfo($EX, $ifRange[1]);
                $condition = $this->getOpVal($opcodes[$ifRange[0]]['op1'], $EX);

                echo $indent, $isElseIf ? 'else if' : 'if', ' (', str($condition, $EX), ') ', '{', PHP_EOL;
                $this->beginScope($EX);
                $this->recognizeAndDecompileClosedBlocks($EX, $ifRange);
                $this->endScope($EX);
                $EX['lastBlock'] = NULL;
                echo $indent, '}', PHP_EOL;

                $isElseIf = TRUE;
                // search for else if dima2k IF constr fix
                $range[0] = $ifRange[1] + 1;
                for ($i = $ifRange[1] + 1; $i <= $range[1]; ++$i) {
                    $this->dasmBasicBlock($EX, array($i, $i));
                    if (isset($EX['opcodes'][$i]['php'])) {
                        break;
                    }
                    $isElseIf = TRUE;
                    // find first jmpout
                    if (!empty($opcodes[$i]['jmpouts'])) {
                        if ($this->isIfCondition($EX, array($i, $range[1]))) {
                            $this->dasmBasicBlock($EX, array($range[0], $i));
                            $range[0] = $i;
                        }
                        break;
                    }
                }
            } while ($this->isIfCondition($EX, $range));
            if ($ifRange[1] < $range[1]) {
                $elseRange = array($ifRange[1], $range[1]);
                echo $indent, 'else ', '{', PHP_EOL;
                $this->beginScope($EX);
                $this->recognizeAndDecompileClosedBlocks($EX, $elseRange);
                $this->endScope($EX);
                $EX['lastBlock'] = NULL;
                echo $indent, '}', PHP_EOL;
            }
            $this->endComplexBlock($EX);
            return;
        }
        if ($firstOp['opcode'] == XC_JMPZ && !empty($firstOp['jmpouts'])
            && $firstOp['jmpouts'][0] - 1 == $range[1]
            && ($opcodes[$firstOp['jmpouts'][0] - 1]['opcode'] == XC_RETURN || $opcodes[$firstOp['jmpouts'][0] - 1]['opcode'] == XC_GENERATOR_RETURN || (ZEND_ENGINE_2_4 &&  $opcodes[$firstOp['jmpouts'][0] - 1]['opcode'] == XC_RETURN_BY_REF) )
        ) {
            $this->beginComplexBlock($EX);
            $this->removeJmpInfo($EX, $range[0]);
            $condition = $this->getOpVal($opcodes[$range[0]]['op1'], $EX);

            echo $indent, 'if (', str($condition, $EX), ') ', '{', PHP_EOL;
            $this->beginScope($EX);
            $this->recognizeAndDecompileClosedBlocks($EX, $range);
            $this->endScope($EX);
            echo $indent, '}', PHP_EOL;
            $this->endComplexBlock($EX);
            return;
        }

        // if(!$expr) { $aaa; }
        if ($firstOp['opcode'] == XC_JMPNZ && !empty($firstOp['jmpouts'])
            && $firstOp['jmpouts'][0] - 1 == $range[1]
        ) {
            $this->beginComplexBlock($EX);
            $this->removeJmpInfo($EX, $range[0]);
            $condition = $this->getOpVal($opcodes[$range[0]]['op1'], $EX);

            echo $indent, 'if (!', str($condition, $EX), ') ', '{', PHP_EOL;
            $this->beginScope($EX);
            $this->recognizeAndDecompileClosedBlocks($EX, $range);
            $this->endScope($EX);
            echo $indent, '}', PHP_EOL;
            $this->endComplexBlock($EX);
            return;
        }
        // }}}

        // {{{ do/while
        if ($lastOp['opcode'] == XC_JMPNZ && !empty($lastOp['jmpouts'])
            && $lastOp['jmpouts'][0] == $range[0]
        ) {
            $this->removeJmpInfo($EX, $range[1]);
            $this->beginComplexBlock($EX);

            echo $indent, "do {", PHP_EOL;
            $this->beginScope($EX);
            $this->recognizeAndDecompileClosedBlocks($EX, $range);
            $this->endScope($EX);
            echo $indent, "} while (", str($this->getOpVal($lastOp['op1'], $EX)), ');', PHP_EOL;

            $this->endComplexBlock($EX);
            return;
        }
        // }}}

        // {{{ try/catch
        if (!empty($firstOp['jmpins']) && !empty($opcodes[$firstOp['jmpins'][0]]['isCatchBegin'])) {
            $catchBlocks = array();
            $catchFirst = $firstOp['jmpins'][0];

            $tryRange = array($range[0], $catchFirst - 1);

            // search for XC_CATCH
            for ($i = $catchFirst; $i <= $range[1]; ) {
                if ($opcodes[$i]['opcode'] == XC_CATCH) {
                    $catchOpLine = $i;
                    $this->removeJmpInfo($EX, $catchFirst);

                    $catchNext = $opcodes[$catchOpLine]['extended_value'];
                    $catchBodyLast = $catchNext - 1;
                    if ($opcodes[$catchBodyLast]['opcode'] == XC_JMP) {
                        --$catchBodyLast;
                    }

                    $catchBlocks[$catchFirst] = array($catchOpLine, $catchBodyLast);

                    $i = $catchFirst = $catchNext;
                }
                else {
                    ++$i;
                }
            }

            if ($opcodes[$tryRange[1]]['opcode'] == XC_JMP) {
                --$tryRange[1];
            }

            $this->beginComplexBlock($EX);
            echo $indent, "try {", PHP_EOL;
            $this->beginScope($EX);
            $this->recognizeAndDecompileClosedBlocks($EX, $tryRange);
            $this->endScope($EX);
            echo $indent, '}', PHP_EOL;
            if (!$catchBlocks) {
                printBacktrace();
                assert($catchBlocks);
            }
            foreach ($catchBlocks as $catchFirst => $catchInfo) {
                list($catchOpLine, $catchBodyLast) = $catchInfo;
                $catchBodyFirst = $catchOpLine + 1;
                $this->dasmBasicBlock($EX, array($catchFirst, $catchOpLine));
                $catchOp = &$opcodes[$catchOpLine];
                echo $indent, 'catch ('
                , $this->stripNamespace(isset($catchOp['op1']['constant']) ? $catchOp['op1']['constant'] : str($this->getOpVal($catchOp['op1'], $EX)))
                , ' '
                , isset($catchOp['op2']['constant']) ? '$' . $catchOp['op2']['constant'] : str($this->getOpVal($catchOp['op2'], $EX))
                , ") {", PHP_EOL;
                unset($catchOp);

                $EX['lastBlock'] = null;
                $this->beginScope($EX);
                $this->recognizeAndDecompileClosedBlocks($EX, array($catchBodyFirst, $catchBodyLast));
                $this->endScope($EX);
                echo $indent, '}', PHP_EOL;
            }
            $this->endComplexBlock($EX);
            return;
        }
        // }}}
        // {{{ switch/case
        if ($firstOp['opcode'] == XC_SWITCH_FREE && isset($T[$firstOp['op1']['var']])) {
            // TODO: merge this code to CASE code. use SWITCH_FREE to detect begin of switch by using $Ts if possible
            $this->beginComplexBlock($EX);
            echo $indent, 'switch (', str($this->getOpVal($firstOp['op1'], $EX)), ") {", PHP_EOL;
            echo $indent, '}', PHP_EOL;
            $this->endComplexBlock($EX);
            return;
        }

        if (
            ($firstOp['opcode'] == XC_CASE
                || $firstOp['opcode'] == XC_JMP && !empty($firstOp['jmpouts']) && ($opcodes[$firstOp['jmpouts'][0]]['opcode'] == XC_CASE || $opcodes[$firstOp['jmpouts'][0]]['opcode'] == XC_JMP)
            )
            && !empty($lastOp['jmpouts'])
        ) {
            $cases = array();
            $caseDefault = NULL;
            $caseOp = NULL;
            for ($i = $range[0]; $i <= $range[1];) {
                $op = $opcodes[$i];
                if ($op['opcode'] == XC_CASE) {
                    if (!isset($caseOp)) {
                        $caseOp = $op;
                    }
                    $jmpz = $opcodes[$i + 1];
                    assert('$jmpz["opcode"] == XC_JMPZ');
                    $caseNext = $jmpz['jmpouts'][0];
                    $cases[$i] = $caseNext - 1;

                    // удаление ссылок с команд очитки переменной-условия
                    for ($k = $i+1; $k<$caseNext; $k++) {
                        if (($opcodes[$k]['opcode'] == XC_SWITCH_FREE || $opcodes[$k]['opcode'] == XC_FREE ) &&
                            $opcodes[$k]['op1']['op_type'] == $op['op1']['op_type'] && $opcodes[$k]['op1']['var'] == $op['op1']['var'] ) {
                            $this->removeJmpInfo($EX, $k);
                        }
                    }
                    $i = $caseNext;
                }
                elseif ($op['opcode'] == XC_JMP && $op['jmpouts'][0] >= $i) {
                    // default
                    $caseNext = $op['jmpouts'][0];
                    $caseDefault = $i;
                    $cases[$i] = $caseNext - 1;

                    // удаление ссылок с команд очитки переменной-условия
                    for ($k = $i+1; $k<$caseNext; $k++) {
                        if (($opcodes[$k]['opcode'] == XC_SWITCH_FREE || $opcodes[$k]['opcode'] == XC_FREE )) {
                            $this->removeJmpInfo($EX, $k);
                        }
                    }


                    $i = $caseNext;
                }
                else {
                    ++$i;
                }
            }

            $this->beginComplexBlock($EX);
            if ($caseOp) {
                $caseCondOp = str($this->getOpVal($caseOp['op1'], $EX), $EX);
            } elseif ($firstOp['opcode'] == XC_JMP && !empty($firstOp['jmpouts']) && $opcodes[$firstOp['jmpouts'][0]]['opcode'] == XC_JMP) {
                if (isset($opcodes[$range[1] + 1]) && $opcodes[$range[1] + 1]['opcode'] == XC_SWITCH_FREE || $opcodes[$range[1] + 1]['opcode'] == XC_FREE) {
                    $caseCondOp = str($this->getOpVal($opcodes[$range[1] + 1]['op1'], $EX), $EX);
                } else {
                    $caseCondOp = '1';
                }
            }
            echo $indent, 'switch (',$caseCondOp , ") {", PHP_EOL;
            $caseIsOut = FALSE;
            foreach ($cases as $caseFirst => $caseLast) {
                if ($caseIsOut && empty($lastCaseFall)) {
                    echo PHP_EOL;
                }

                $caseOp = $opcodes[$caseFirst];

                echo $indent;
                if ($caseOp['opcode'] == XC_CASE) {
                    if ($caseFirst > 0 && $opcodes[$caseFirst - 1]["opcode"] == XC_FETCH_CONSTANT) {
                        if ($opcodes[$caseFirst - 1]['op1']['op_type'] == XC_IS_UNUSED) {
                            $resvar = $this->stripNamespace($opcodes[$caseFirst - 1]['op2']['constant']);
                        }
                        else {
                            if ($opcodes[$caseFirst - 1]['op1']['op_type'] == XC_IS_CONST) {
                                $resvar = $this->stripNamespace($opcodes[$caseFirst - 1]['op1']['constant']);
                            }
                            else {
                                $resvar = $this->getOpVal($opcodes[$caseFirst - 1]['op1'], $EX);
                            }
                        }
                    }
                    else {
                        $resvar = $this->getOpVal($caseOp['op2'], $EX);
                    }
                    echo 'case ';
                    echo str($resvar, $EX);
                    echo ':', PHP_EOL;

                    $this->removeJmpInfo($EX, $caseFirst);
                    ++$caseFirst;

                    assert('$opcodes[$caseFirst]["opcode"] == XC_JMPZ');
                    $this->removeJmpInfo($EX, $caseFirst);
                    ++$caseFirst;
                }
                else {
                    echo 'default';
                    echo ':', PHP_EOL;

                    assert('$opcodes[$caseFirst]["opcode"] == XC_JMP');
                    $this->removeJmpInfo($EX, $caseFirst);
                    ++$caseFirst;
                }

                assert('$opcodes[$caseLast]["opcode"] == XC_JMP');
                $this->removeJmpInfo($EX, $caseLast);
                --$caseLast;
                switch ($opcodes[$caseLast]['opcode']) {
                    case XC_BRK:
                    case XC_CONT:
                    case XC_GOTO:
                        $lastCaseFall = FALSE;
                        break;

                    default:
                        $lastCaseFall = TRUE;
                }

                $this->beginScope($EX);
                $this->recognizeAndDecompileClosedBlocks($EX, array($caseFirst, $caseLast));
                $this->endScope($EX);
                $caseIsOut = TRUE;
            }
            echo $indent, '}', PHP_EOL;

            $this->endComplexBlock($EX);
            if (isset($opcodes[$range[1] + 1]) && $opcodes[$range[1] + 1]['opcode'] == XC_SWITCH_FREE || $opcodes[$range[1] + 1]['opcode'] == XC_FREE) {
                $this->removeJmpInfo($EX, $range[1] + 1);
            }
            return;
        }
        // }}}

        // {{{ search firstJmpOp
        $firstJmp = NULL;
        $firstJmpOp = NULL;
        for ($i = $range[0]; $i <= $range[1]; ++$i) {
            if (!empty($opcodes[$i]['jmpouts'])) {
                $firstJmp = $i;
                $firstJmpOp = & $opcodes[$firstJmp];
                break;
            }
        }
        // }}}

        // {{{ while
        if (isset($firstJmpOp)
            && $firstJmpOp['opcode'] == XC_JMPZ
            && $firstJmpOp['jmpouts'][0] > $range[1]
            && $lastOp['opcode'] == XC_JMP && !empty($lastOp['jmpouts'])
            && $lastOp['jmpouts'][0] <= $range[0]
        ) { //dima2k fix
            $this->removeJmpInfo($EX, $firstJmp);
            $this->removeJmpInfo($EX, $range[1]);
            $this->beginComplexBlock($EX);

            ob_start();
            $this->beginScope($EX);
            $this->recognizeAndDecompileClosedBlocks($EX, $range);
            $this->endScope($EX);
            $body = ob_get_clean();

            echo $indent, 'while (', str($this->getOpVal($firstJmpOp['op1'], $EX)), ") {", PHP_EOL;
            echo $body;
            echo $indent, '}', PHP_EOL;

            $this->endComplexBlock($EX);
            return;
        }
        // }}}
        // {{{ foreach
        if (isset($firstJmpOp)
            && $firstJmpOp['opcode'] == XC_FE_FETCH
            && $firstJmpOp['jmpouts'][0] > $range[1]
            && $lastOp['opcode'] == XC_JMP && !empty($lastOp['jmpouts'])
            && $lastOp['jmpouts'][0] == $firstJmp
        ) {
            $this->removeJmpInfo($EX, $firstJmp);
            $this->removeJmpInfo($EX, $range[1]);
            $this->beginComplexBlock($EX);
            ob_start();
            $this->beginScope($EX);
            $this->recognizeAndDecompileClosedBlocks($EX, $range);
            $this->endScope($EX);
            $body = ob_get_clean();
            $as = foldToCode($firstJmpOp['fe_as'], $EX); // foreach
            if (isset($firstJmpOp['fe_key'])) {
                $as = str($firstJmpOp['fe_key'], $EX) . ' => ' . ($firstJmpOp['extended_value'] & ZEND_FE_FETCH_BYREF ? '&' : '' ) .str($as);
            }
            else {
                $as = ($firstJmpOp['extended_value'] & ZEND_FE_FETCH_BYREF ? '&' : '' ). str($as); //StealthDebuger foreach fix code
            }
            echo $indent, 'foreach (', str($firstJmpOp['fe_src'], $EX), " as $as ) {", PHP_EOL;
            echo $body;
            echo $indent, '}', PHP_EOL;

            $this->endComplexBlock($EX);
            if (isset($opcodes[$range[1] + 1]) && $opcodes[$range[1] + 1]['opcode'] == XC_SWITCH_FREE || $opcodes[$range[1] + 1]['opcode'] == XC_FREE) {
                $this->removeJmpInfo($EX, $range[1] + 1);
            }
            return;
        }
        // }}}

        //$this->decompileBasicBlock($EX, $range, true);
        $this->decompileBasicBlock($EX, array($range[0], $range[0])); //dima2k while temp fix
        $this->recognizeAndDecompileClosedBlocks($EX, array($range[0] + 1, $range[1])); //dima2k while temp fix
    }

    // }}}
    function recognizeAndDecompileClosedBlocks(&$EX, $range) // {{{ decompile in a tree way
    {
        $opcodes = & $EX['opcodes'];
        $starti = $range[0];
        for ($i = $starti; $i <= $range[1];) {
            if ((!empty($opcodes[$i]['jmpins']) && $i >= $starti) || !empty($opcodes[$i]['jmpouts'])) { // dima2k fix
                $blockFirst = $i;
                $blockLast = -1;
                $j = $blockFirst;
                do {
                    $op = $opcodes[$j];
                    if (!empty($op['jmpins'])) {
                        // care about jumping from blocks behind, not before
                        foreach ($op['jmpins'] as $oplineNumber) {
                            if ($oplineNumber <= $range[1] && $blockLast < $oplineNumber) {
                                $blockLast = $oplineNumber;
                            }
                        }
                    }
                    if (!empty($op['jmpouts'])) {
                        $blockLast = max($blockLast, max($op['jmpouts']) - 1);
                    }
                    ++$j;
                } while ($j <= $blockLast);
                if (!assert('$blockLast <= $range[1]')) {
                    var_dump($blockLast, $range[1]);
                }

                if ($blockLast >= $blockFirst) {
                    if ($blockFirst > $starti) {
                        $this->decompileBasicBlock($EX, array($starti, $blockFirst - 1));
                    }
                    if ($this->decompileComplexBlock($EX, array($blockFirst, $blockLast)) === FALSE) {
                        if ($EX['lastBlock'] == 'complex') {
                            echo PHP_EOL;
                        }
                        $EX['lastBlock'] = NULL;
                    }
                    $starti = $blockLast + 1;
                    $i = $starti;
                }
                else {
                    ++$i;
                }
            }
            else {
                ++$i;
            }
        }
        if ($starti <= $range[1]) {
            $this->decompileBasicBlock($EX, array($starti, $range[1]));
        }
    }

    // }}}
    function &dop_array($op_array, $indent = '') // {{{
    {
        $op_array['opcodes'] = $this->fixOpcode($op_array['opcodes'], TRUE, $indent == '' ? 1 : NULL);
        $opcodes = & $op_array['opcodes'];
        $last = count($opcodes) - 1;

        if (isset($op_array['try_catch_array']) && $op_array['try_catch_array']) {
            foreach ($op_array['try_catch_array'] as $try_catch_element) {
                $catch_op = $try_catch_element['catch_op'];
                $try_op = $try_catch_element['try_op'];
                $opcodes[$try_op]['jmpins'][] = $catch_op;
                $opcodes[$catch_op]['jmpouts'][] = $try_op;
                $opcodes[$catch_op]['isCatchBegin'] = TRUE;
            }
        }

        /*    // deobfuscate vars
            if (isset($op_array['vars'])) {
              foreach ($op_array['vars'] as $vid=>$var) {
                if (!preg_match("!^[a-zA-Z_][a-zA-Z0-9_]*$!",$var['name'])) {
                  $op_array['vars'][$vid]['name'] = fixFunctionName($op_array['vars'][$vid]['name']);
                  $op_array['vars'][$vid]['name_len'] = strlen($op_array['vars'][$vid]['name']);
                }
              }
            }*/

        // {{{ build jmpins/jmpouts to op_array
        for ($i = 0; $i <= $last; $i++) {
            $op = & $opcodes[$i];
            $op['line'] = $i;
            switch ($op['opcode']) {
                case XC_CONT:
                case XC_BRK:
                    $op['jmpouts'] = array();
                    break;

                case XC_GOTO:
                    $target = $op['op1']['var'];
                    $op['goto'] = $target;
                    $opcodes[$target]['gofrom'][] = $i;
                    break;

                case XC_JMP:
                    $target = $op['op1']['var'];
                    $op['jmpouts'] = array($target);
                    $opcodes[$target]['jmpins'][] = $i;
                    break;

                case XC_JMPZNZ:
                    $jmpz = $op['op2']['opline_num'];
                    $jmpnz = $op['extended_value'];
                    $op['jmpouts'] = array($jmpz, $jmpnz);
                    $opcodes[$jmpz]['jmpins'][] = $i;
                    $opcodes[$jmpnz]['jmpins'][] = $i;
                    break;

                case XC_JMPZ:
                case XC_JMPNZ:
                case XC_JMPZ_EX:
                case XC_JMPNZ_EX:
                    // case XC_JMP_SET:
                    // case XC_FE_RESET:
                case XC_FE_FETCH:
                    // case XC_JMP_NO_CTOR:
                    $target = $op['op2']['opline_num'];
                    //if (!isset($target)) {
                    //	$this->dumpop($op, $EX);
                    //	var_dump($op); exit;
                    //}
                    $op['jmpouts'] = array($target);
                    $opcodes[$target]['jmpins'][] = $i;
                    break;

                /*
                case XC_RETURN:
                    $op['jmpouts'] = array();
                    break;
                */

                case XC_SWITCH_FREE:
                    $op['jmpouts'] = array($i + 1);
                    $opcodes[$i + 1]['jmpins'][] = $i;
                    break;

                case XC_CASE:
                    // just to link together
                    $op['jmpouts'] = array($i + 2);
                    $opcodes[$i + 2]['jmpins'][] = $i;
                    break;

                case XC_CATCH:
                    $catchNext = $op['extended_value'];
                    $catchBegin = $opcodes[$i - 1]['opcode'] == XC_FETCH_CLASS ? $i - 1 : $i;
                    $opcodes[$catchBegin]['jmpouts'] = array($catchNext);
                    $opcodes[$catchNext]['jmpins'][] = $catchBegin;
                    break;
            }
            /*
            if (!empty($op['jmpouts']) || !empty($op['jmpins'])) {
                echo $i, "\t", xcache_get_opcode($op['opcode']), PHP_EOL;
            }
            // */
        }
        unset($op);
        if (isset($op_array['try_catch_array'])) {
            foreach ($op_array['try_catch_array'] as $try_catch_element) {
                $catch_op = $try_catch_element['catch_op'];
                $opcodes[$catch_op]['isCatchBegin'] = true;
            }
            foreach ($op_array['try_catch_array'] as $try_catch_element) {
                $catch_op = $try_catch_element['catch_op'];
                $try_op = $try_catch_element['try_op'];
                do {
                    $opcodes[$try_op]['jmpins'][] = $catch_op;
                    $opcodes[$catch_op]['jmpouts'][] = $try_op;
                    if ($opcodes[$catch_op]['opcode'] == XC_CATCH) {
                        $catch_op = $opcodes[$catch_op]['extended_value'];
                    }
                    else if (@$opcodes[$catch_op + 1]['opcode'] == XC_CATCH) {
                        $catch_op = $opcodes[$catch_op + 1]['extended_value'];
                    }
                    else {
                        break;
                    }
                } while ($catch_op <= $last && empty($opcodes[$catch_op]['isCatchBegin']));
            }
        }
        // }}}
        // build semi-basic blocks
        $nextbbs = array();
        $starti = 0;
        for ($i = 1; $i <= $last; $i++) {
            if (isset($opcodes[$i]['jmpins'])
                || isset($opcodes[$i - 1]['jmpouts'])
            ) {
                $nextbbs[$starti] = $i;
                $starti = $i;
            }
        }
        $nextbbs[$starti] = $last + 1;

        $EX = array();
        $EX['Ts'] = array();
        $EX['indent'] = $indent;
        $EX['nextbbs'] = $nextbbs;
        $EX['op_array'] = & $op_array;
        $EX['opcodes'] = & $opcodes;
        $EX['range'] = array(0, count($opcodes) - 1);
        // func call
        $EX['object'] = NULL;
        $EX['called_scope'] = NULL;
        $EX['fbc'] = NULL;
        $EX['argstack'] = array();
        $EX['arg_types_stack'] = array();
        $EX['scopeStack'] = array();
        $EX['silence'] = 0;
        $EX['recvs'] = array();
        $EX['uses'] = array();
        $EX['lastBlock'] = NULL;
        $EX['dims'] = array();

        /* dump whole array
        $this->keepTs = true;
        $this->dasmBasicBlock($EX, $range);
        for ($i = $range[0]; $i <= $range[1]; ++$i) {
            echo $i, "\t", $this->dumpop($opcodes[$i], $EX);
        }
        // */
        // decompile in a tree way
        $this->recognizeAndDecompileClosedBlocks($EX, $EX['range'], $EX['indent']);
        return $EX;
    }

    // }}}
    function dasmBasicBlock(&$EX, $range) // {{{
    {
        $T = & $EX['Ts'];
        $opcodes = & $EX['opcodes'];
        $lastphpop = NULL;

        for ($i = $range[0]; $i <= $range[1]; $i++) {
            // {{{ prepair
            $op = & $opcodes[$i];
            $opc = $op['opcode'];
            if ($opc == XC_NOP) {
                $this->usedOps[$opc] = TRUE;
                continue;
            }

            $op1 = $op['op1'];
            $op2 = $op['op2'];
            $res = $op['result'];
            $ext = $op['extended_value'];

            $opname = xcache_get_opcode($opc);

            if ($opname == 'UNDEF' || !isset($opname)) {
                echo '// UNDEF OP:';
                $this->dumpop($op, $EX);
                continue;
            }
            // echo $i, ' '; $this->dumpop($op, $EX); //var_dump($op);

            $resvar = NULL;
            unset($curResVar);
            if (array_key_exists($res['var'], $T)) {
                $curResVar = & $T[$res['var']];
            }
            if ((ZEND_ENGINE_2_4 ? ($res['op_type'] & EXT_TYPE_UNUSED) : ($res['EA.type'] & EXT_TYPE_UNUSED)) || $res['op_type'] == XC_IS_UNUSED) {
                $istmpres = FALSE;
            }
            else {
                $istmpres = TRUE;
            }
            // }}}
            // echo $opname, "\n";

            $notHandled = FALSE;
            switch ($opc) {
                case XC_NEW: // {{{
                    array_push($EX['arg_types_stack'], array($EX['fbc'], $EX['object'], $EX['called_scope']));
                    $EX['object'] = (int) $res['var'];
                    $EX['called_scope'] = NULL;
                    $EX['fbc'] = 'new ' . unquoteName($this->getOpVal($op1, $EX), $EX);
                    if (!ZEND_ENGINE_2) {
                        $resvar = '$new object$';
                    }
                    break;
                // }}}
                case XC_THROW: // {{{
                    $resvar = 'throw ' . str($this->getOpVal($op1, $EX));
                    break;
                // }}}
                case XC_CLONE: // {{{
                    $resvar = 'clone ' . str($this->getOpVal($op1, $EX));
                    break;
                // }}}
                case XC_CATCH: // {{{
                    break;
                // }}}
                case XC_INSTANCEOF: // {{{
                    $resvar = str($this->getOpVal($op1, $EX)) . ' instanceof ' . str($this->getOpVal($op2, $EX));
                    break;
                // }}}
                case XC_FETCH_CLASS: // {{{
                    if ($op2['op_type'] == XC_IS_UNUSED) {
                        switch (($ext & (defined('ZEND_FETCH_CLASS_MASK') ? ZEND_FETCH_CLASS_MASK : 0xFF))) {
                            case ZEND_FETCH_CLASS_SELF:
                                $class = 'self';
                                break;
                            case ZEND_FETCH_CLASS_PARENT:
                                $class = 'parent';
                                break;
                            case ZEND_FETCH_CLASS_STATIC:
                                $class = 'static';
                                break;
                        }
                        $istmpres = TRUE;
                    }
                    else {
                        $class = $this->getOpVal($op2, $EX);
                        if (isset($op2['constant'])) {
                            $class = $this->stripNamespace(unquoteName($class));
                        }
                    }
                    $resvar = $class;
                    break;
                // }}}
                case XC_FETCH_CONSTANT: // {{{
                    if ($op1['op_type'] == XC_IS_UNUSED) {
                        $resvar = $this->stripNamespace($op2['constant']);
                        break;
                    }

                    if ($op1['op_type'] == XC_IS_CONST) {
                        $resvar = $this->stripNamespace($op1['constant']);
                    }
                    else {
                        $resvar = $this->getOpVal($op1, $EX);
                    }

                    $resvar = str($resvar) . '::' . unquoteName($this->getOpVal($op2, $EX));
                    break;
                // }}}
                // {{{ case XC_FETCH_*
                case XC_FETCH_R:
                case XC_FETCH_W:
                case XC_FETCH_RW:
                case XC_FETCH_FUNC_ARG:
                case XC_FETCH_UNSET:
                case XC_FETCH_IS:
                    $fetchType = defined('ZEND_FETCH_TYPE_MASK') ? ($ext & ZEND_FETCH_TYPE_MASK) : $op2[!ZEND_ENGINE_2 ? 'fetch_type' : 'EA.type'];
                    $name = isset($op1['constant']) ? $op1['constant'] : unquoteName($this->getOpVal($op1, $EX), $EX);
                    if ($fetchType == ZEND_FETCH_STATIC_MEMBER) {
                        $class = isset($op2['constant']) ? $op2['constant'] : $this->getOpVal($op2, $EX);
                        $rvalue = $this->stripNamespace($class) . '::$' . $name;
                    }
                    else {
                        $rvalue = isset($op1['constant']) ? $op1['constant'] : $this->getOpVal($op1, $EX);
                        $globalName = xcache_is_autoglobal($name) ? "\$$name" : "\$GLOBALS[" . str($this->getOpVal($op1, $EX), $EX) . "]";
                        $rvalue = new Decompiler_Fetch($rvalue, $fetchType, $globalName);
                    }

                    if ($res['op_type'] != XC_IS_UNUSED) {
                        $resvar = $rvalue;
                    }
                    break;
                // }}}
                case XC_UNSET_VAR: // {{{
                    $fetchType = defined('ZEND_FETCH_TYPE_MASK') ? ($ext & ZEND_FETCH_TYPE_MASK) : $op2['EA.type'];
                    if ($fetchType == ZEND_FETCH_STATIC_MEMBER) {
                        $class = isset($op2['constant']) ? $op2['constant'] /* PHP5.3- */ : $this->getOpVal($op2, $EX);
                        $rvalue = $this->stripNamespace($class) . '::$' . $op1['constant'];
                    }
                    else {
                        $rvalue = isset($op1['constant']) ? '$' . $op1['constant'] /* PHP5.1- */ : $this->getOpVal($op1, $EX);
                    }

                    $op['php'] = "unset(" . str($rvalue, $EX) . ")";
                    $lastphpop = &$op;
                    break;
                // }}}
                // {{{ case FETCH_DIM_*
                case XC_FETCH_DIM_TMP_VAR:
                case XC_FETCH_DIM_R:
                case XC_FETCH_DIM_W:
                case XC_FETCH_DIM_RW:
                case XC_FETCH_DIM_FUNC_ARG:
                case XC_FETCH_DIM_UNSET:
                case XC_FETCH_DIM_IS:
                case XC_ASSIGN_DIM:
                case XC_UNSET_DIM:
                case XC_UNSET_DIM_OBJ:
                case XC_UNSET_OBJ:
                    $src = $this->getOpVal($op1, $EX);
                    if (is_a($src, "Decompiler_ForeachBox")) {
                        $src->iskey = $this->getOpVal($op2, $EX);
                        $resvar = $src;
                        break;
                    }
                    if (is_a($src, "Decompiler_DimBox")) {
                        $dimbox = $src;
                    } else {
                        switch($op1['op_type']){
                            case XC_IS_CONST:
                                @$list_link = $EX['lists'][XC_IS_CONST][$op1['constant']];
                                break;
                            case XC_IS_CV:
                                @$list_link = &$EX['lists'][XC_IS_CV][$op1['var']];
                                break;
                            default:
                                @$list_link = &$T[$op1['var']];
                        }
                        // TODO: РїРµСЂРµРґРµР»Р°С‚СЊ СЂР°СЃРїРѕР·РЅРѕРІР°РЅРёРµ list(), СЃРµР№С‡Р°СЃ РІРѕР·РјРѕР¶РЅРѕ СЌС‚Рѕ СЃРґРµР»Р°С‚СЊ РїСЂР°РєС‚РёС‡РµСЃРєРё РґР»СЏ РІСЃРµС… РІР°СЂРёР°РЅС‚РѕРІ
                        /*if (!is_a($src, "Decompiler_ListBox") && isset($list_link) && is_a($list_link,"Decompiler_ListBox")) {
                          if ($list_link->obj->dims[count($list_link->obj->dims)-1]->isLast) {
                            $list_link->obj->dims = array();
                            unset($list_link);
                          } else {
                            $src = $list_link;
                            unset($list_link);
                          }
                        } else */if (!is_a($src, "Decompiler_ListBox") ) {
                            $op1val = $this->getOpVal($op1, $EX);
                            $list = new Decompiler_List($op1val);
                            $src = new Decompiler_ListBox($list);
                            if (!isset($op1['var']) && $op1['op_type'] != XC_IS_CONST) {
                                $this->dumpop($op, $EX);
                                var_dump($op);
                                die('missing var');
                            }
                            switch($op1['op_type']){
                                case XC_IS_CONST:
                                    $EX['lists'][XC_IS_CONST][$op1['constant']] = $src;
                                    break;
                                case XC_IS_CV:
                                    $EX['lists'][XC_IS_CV][$op1['var']] = $src;
                                    break;
                                default:
                                    $T[$op1['var']] = $src;
                            }
                            unset($list);
                        }
                        $dim = new Decompiler_Dim($src);
                        $src->obj->dims[] = & $dim;
                        $dimbox = new Decompiler_DimBox($dim);
                    }
                    $dim = &$dimbox->obj;
                    $dim->offsets[] = $this->getOpVal($op2, $EX);

                    if ($ext == ZEND_FETCH_ADD_LOCK) {
                        $src->obj->everLocked = TRUE;
                        if ($opc == XC_FETCH_DIM_R && $op1['op_type'] == XC_IS_CV) {
                            $dim->isLast = true;
                            $src->obj->everLocked = false;
                        }
                    }
                    else {
                        if ($ext == ZEND_FETCH_STANDARD && (!$i || $opcodes[$i - 1]['opcode'] != XC_FETCH_DIM_TMP_VAR)) {
                            $dim->isLast = TRUE;
                            // РґР»СЏ list(,$a)=each($b)
                            if ($i && $opc == XC_FETCH_DIM_R && $op1['op_type'] == XC_IS_VAR && in_array($opcodes[$i-1]['opcode'],array(XC_DO_FCALL, XC_DO_FCALL_BY_NAME, XC_DO_FCALL_BY_FUNC, XC_EXT_FCALL_END))) $src->obj->everLocked = TRUE;
                        }
                    }

                    if ($opc == XC_UNSET_OBJ) {
                        $dim->isObject = TRUE;
                    }
                    // РїРѕРёСЃРє РїРѕСЃР»РµРґРЅРµР№ РІСЂРµРјРµРЅРЅРѕР№ РїРµСЂРµРјРµРЅРЅРѕР№ РІ list();
                    if ($opc == XC_FETCH_DIM_TMP_VAR && $op1['op_type'] == XC_IS_TMP_VAR ) {
                        for ($ti = $i+1; $ti <= $range[1];$ti++) {
                            if ($opcodes[$ti]['opcode'] == XC_FETCH_DIM_TMP_VAR && $opcodes[$ti]['op1']['op_type'] == XC_IS_TMP_VAR && $opcodes[$ti]['op1']['var'] == $op1['var']) break;
                            if ($opcodes[$ti]['opcode'] == XC_FREE && $opcodes[$ti]['op1']['var'] == $op1['var']) {
                                $dim->isLast = TRUE;
                                break;
                            }
                        }
                    }

                    // РєРѕРЅСЃС‚СЂСѓРєС†РёСЏ РІРёРґР° list($a,$b) = 'asd';
//          if ($opc == XC_FETCH_DIM_TMP_VAR  && $op1['op_type'] == XC_IS_CONST && $i <= $range[1]-1 &&
//            ($opcodes[$i+2]['opcode'] != XC_FETCH_DIM_TMP_VAR || $opcodes[$i+2]['op1']['op_type'] != XC_IS_CONST || $opcodes[$i+2]['op1']['constant'] != $op1['constant'])) {
//            $dim->isLast = TRUE;
//            $dim->obj->everLocked = true;
//          }

                    if ($opc == XC_FETCH_DIM_TMP_VAR  && $op1['op_type'] == XC_IS_CONST ) {
                        $dim->isLast = TRUE;
                        $dim->obj->everLocked = false;
                    }

                    unset($dim);
                    $rvalue = $dimbox;
                    unset($dimbox);

                    if ($opc == XC_ASSIGN_DIM) {
                        $lvalue = $rvalue;
                        ++$i;
                        $rvalue = $this->getOpVal($opcodes[$i]['op1'], $EX);
                        if (is_a($rvalue, "Decompiler_DimBox")) {
                            $dim = & $rvalue->obj;
                            $dim->assign = $lvalue;
                            if ($dim->isLast) {
                                $resvar = (is_object($dim->value) && is_a($dim->value,'Decompiler_ListBox')) ? $dim->value->toObject($this,XC_ASSIGN_DIM) : foldToCode($dim->value);
                            }
                            unset($dim);
                            break;
                        }
                        $resvar = new Decompiler_Binop($this, $lvalue,XC_ASSIGN_DIM ,$rvalue);
                    }
                    else {
                        if ($opc == XC_UNSET_DIM || $opc == XC_UNSET_OBJ) {
                            $op['php'] = "unset(" . str($rvalue, $EX) . ")";
                            $lastphpop = & $op;
                        }
                        else {
                            if ($res['op_type'] != XC_IS_UNUSED) {
                                $resvar = $rvalue;
                            }
                        }
                    }
                    break;
                // }}}
                case XC_ASSIGN: // {{{
                    $lvalue = $this->getOpVal($op1, $EX);
                    $rvalue = $this->getOpVal($op2, $EX);
                    if (is_a($rvalue, 'Decompiler_ForeachBox')) {
                        $type = $rvalue->iskey ? 'fe_key' : 'fe_as';
                        $rvalue->obj[$type] = $lvalue;
                        unset($T[$op2['var']]);
                        break;
                    }

                    if (is_a($rvalue, "Decompiler_DimBox")) {
                        $dim = & $rvalue->obj;
                        $dim->assign = $lvalue;
                        if ($dim->isLast) {
                            $resvar = (is_object($dim->value) && is_a($dim->value,'Decompiler_ListBox')) ? $dim->value->toObject($this,XC_ASSIGN) : foldToCode($dim->value);
                        }
                        unset($dim);
                        break;
                    }

                    if (is_a($rvalue, 'Decompiler_Fetch')) {
                        $src = str($rvalue->src, $EX);
                        $name = unquoteName($src);
                        if ('$' . $name == $lvalue) {
                            switch ($rvalue->fetchType) {
                                case ZEND_FETCH_STATIC:
                                    $statics = & $EX['op_array']['static_variables'];
                                    if (isset($statics[$name]) && (xcache_get_type($statics[$name]) & IS_LEXICAL_VAR)) {
                                        $EX['uses'][] = str($lvalue);
                                        unset($statics);
                                        break 2;
                                    }
                                    unset($statics);
                            }
                        }
                    }

                    $resvar = new Decompiler_Binop($this, $lvalue, XC_ASSIGN, $rvalue);
                    break;
                // }}}
                case XC_ASSIGN_REF: // {{{
                    $lvalue = $this->getOpVal($op1, $EX);
                    $rvalue = $this->getOpVal($op2, $EX);
                    if (is_a($rvalue, 'Decompiler_Fetch')) {
                        $src = str($rvalue->src, $EX);
                        if ('$' . unquoteName($src) == $lvalue) {
                            switch ($rvalue->fetchType) {
                                case ZEND_FETCH_GLOBAL:
                                case ZEND_FETCH_GLOBAL_LOCK:
                                    $resvar = 'global ' . $lvalue;
                                    break 2;
                                case ZEND_FETCH_STATIC:
                                    $statics = & $EX['op_array']['static_variables'];
                                    $name = unquoteName($src);
                                    if (isset($statics[$name]) && (xcache_get_type($statics[$name]) & IS_LEXICAL_REF)) {
                                        $EX['uses'][] = '&' . str($lvalue);
                                        unset($statics);
                                        break 2;
                                    }

                                    $resvar = 'static ' . $lvalue;
                                    if (isset($statics[$name])) {
                                        $var = $statics[$name];
                                        $resvar .= ' = ';
                                        $resvar .= str(value($var), $EX);
                                    }
                                    unset($statics);
                                    break 2;
                                default:
                            }
                        }
                    }

                    if (is_a($rvalue, 'Decompiler_ForeachBox')) {
                        $type = $rvalue->iskey ? 'fe_key' : 'fe_as';
                        $rvalue->obj[$type] = $lvalue;
                        unset($T[$op2['var']]);
                        break;
                    }


                    if (is_a($rvalue, "Decompiler_DimBox")) {
                        $dim = & $rvalue->obj;
                        $dim->assign = $lvalue;
                        $resvar = (is_object($dim->value) && is_a($dim->value,'Decompiler_ListBox')) ? $dim->value->toObject($this,XC_ASSIGN_REF) : foldToCode($dim->value);
                        unset($dim);
                        break;
                    }

                    // TODO: PHP_6 global
                    $resvar = new Decompiler_Binop($this, $lvalue, XC_ASSIGN_REF, $rvalue);
                    break;
                // }}}
                // {{{ case XC_FETCH_OBJ_*
                case XC_FETCH_OBJ_R:
                case XC_FETCH_OBJ_W:
                case XC_FETCH_OBJ_RW:
                case XC_FETCH_OBJ_FUNC_ARG:
                case XC_FETCH_OBJ_UNSET:
                case XC_FETCH_OBJ_IS:
                case XC_ASSIGN_OBJ:
                    $obj = $this->getOpVal($op1, $EX);
                    if (!isset($obj)) {
                        $obj = '$this';
                    }
                    $rvalue = str($obj) . "->" . unquoteVariableName($this->getOpVal($op2, $EX), $EX);
                    if ($res['op_type'] != XC_IS_UNUSED) {
                        $resvar = $rvalue;
                    }
                    if ($opc == XC_ASSIGN_OBJ) {
                        ++ $i;
                        $lvalue = $rvalue;
                        $rvalue = $this->getOpVal($opcodes[$i]['op1'], $EX);
                        $resvar = "$lvalue = " . str($rvalue);
                    }
                    break;
                // }}}
                // }}}
                case XC_ISSET_ISEMPTY_DIM_OBJ:
                case XC_ISSET_ISEMPTY_PROP_OBJ:
                case XC_ISSET_ISEMPTY:
                case XC_ISSET_ISEMPTY_VAR: // {{{
                    if ($opc == XC_ISSET_ISEMPTY_VAR) {
                        $rvalue = $this->getOpVal($op1, $EX);
                        // for < PHP_5_3
                        if ($op1['op_type'] == XC_IS_CONST) {
                            $rvalue = '$' . unquoteVariableName($this->getOpVal($op1, $EX));
                        }
                        $fetchtype = defined('ZEND_FETCH_TYPE_MASK') ? ($ext & ZEND_FETCH_TYPE_MASK) : $op2['EA.type'];
                        if ($fetchtype == ZEND_FETCH_STATIC_MEMBER) {
                            $class = isset($op2['constant']) ? $op2['constant'] : $this->getOpVal($op2, $EX);
                            $rvalue = $this->stripNamespace($class) . '::' . unquoteName($rvalue, $EX);
                        }
                    }
                    else if ($opc == XC_ISSET_ISEMPTY) {
                        $rvalue = $this->getOpVal($op1, $EX);
                    }
                    else {
                        $container = $this->getOpVal($op1, $EX);
                        $dim = $this->getOpVal($op2, $EX);
                        if ($opc == XC_ISSET_ISEMPTY_PROP_OBJ) {
                            if (!isset($container)) {
                                $container = '$this';
                            }
                            $rvalue = str($container, $EX) . "->" . unquoteVariableName($dim);
                        }
                        else {
                            $rvalue = str($container, $EX) . '[' . str($dim) .']';
                        }
                    }
                    //(ZEND_ISSET | ZEND_ISEMPTY)
                    if(stristr((!ZEND_ENGINE_2 ? $op['op2']['var'] /* constant */ : $ext),'empty')){
                        $rvalue = "empty(" . str($rvalue) . ")";
                    }
                    switch (((!ZEND_ENGINE_2 ? $op['op2']['var'] /* constant */ : $ext) & ZEND_ISSET_ISEMPTY_MASK)) {
                        //switch (((!ZEND_ENGINE_2 ? $op['op2']['var'] /* constant */ : $ext) & (ZEND_ISSET | ZEND_ISEMPTY))) {
                        case ZEND_ISSET:
                            $rvalue = "isset(" . str($rvalue) . ")";
                            break;
                        case ZEND_ISEMPTY:
                            $rvalue = "empty(" . str($rvalue) . ")";
                            break;
                    }
                    $resvar = $rvalue;
                    break;
                // }}}
                case XC_SEND_REF:
                case XC_SEND_VAR: // {{{
                    // TODO: РїРѕ РёРґРµРµ, РЅСѓР¶РЅРѕ СЃРґРµР»Р°С‚СЊ РѕРїС†РёРѕРЅР°Р»СЊРЅРѕР№ РѕС‚РєР»СЋС‡РµРЅРёРµ & РґР»СЏ  РїРµСЂРµРјРµРЅРЅС‹С… РІ С„СѓРЅРєС†РёСЏС…
                    // $ref = ($opc == XC_SEND_REF && isset($opcodes[$i + 1]) && $opcodes[$i + 1]['opcode'] != XC_DO_FCALL ? '&' : '');
                    $EX['argstack'][] = /* $ref.*/ str($this->getOpVal($op1, $EX));
                    break;

                case XC_SEND_VAR_NO_REF:
                case XC_SEND_VAL:
                    $EX['argstack'][] = str($this->getOpVal($op1, $EX));
                    break;

                // }}}
                case XC_INIT_STATIC_METHOD_CALL:
                case XC_INIT_METHOD_CALL: // {{{
                    array_push($EX['arg_types_stack'], array($EX['fbc'], $EX['object'], $EX['called_scope']));
                    if ($opc == XC_INIT_STATIC_METHOD_CALL || $opc == XC_INIT_METHOD_CALL || $op1['op_type'] != XC_IS_UNUSED) {
                        $obj = $this->getOpVal($op1, $EX);
                        if ($opc == XC_INIT_STATIC_METHOD_CALL || /* PHP4 */
                            isset($op1['constant'])
                        ) {
                            $EX['object'] = NULL;
                            $EX['called_scope'] = $this->stripNamespace(unquoteName($obj, $EX));
                        }
                        else {
                            $obj = $this->getOpVal($op1, $EX); //as2227654 this fix for Zend54 like $request = getRequest();  to $request = $this->getRequest();
                            if (!isset($obj)) {
                                $obj = '$this';
                            }
                            $EX['object'] = $obj;
                            $EX['called_scope'] = null;
                        }
                        if ($res['op_type'] != XC_IS_UNUSED) {
                            $resvar = '$obj call$';
                        }
                    }
                    else {
                        $EX['object'] = NULL;
                        $EX['called_scope'] = NULL;
                    }

                    $EX['fbc'] = $this->getOpVal($op2, $EX);
                    if (($opc == XC_INIT_STATIC_METHOD_CALL || $opc == XC_INIT_METHOD_CALL) && !isset($EX['fbc'])) {
                        $EX['fbc'] = '__construct';
                    }
                    break;
                // }}}
                case XC_INIT_NS_FCALL_BY_NAME:
                case XC_INIT_FCALL_BY_NAME: // {{{
                    array_push($EX['arg_types_stack'], array($EX['fbc'], $EX['object'], $EX['called_scope']));
                    if (!ZEND_ENGINE_2 && ($ext & ZEND_CTOR_CALL)) {
                        break;
                    }
                    $EX['object'] = NULL;
                    $EX['called_scope'] = NULL;
                    $EX['fbc'] = $this->getOpVal($op2, $EX);
                    break;
                // }}}
                case XC_INIT_FCALL_BY_FUNC: // {{{ deprecated even in PHP 4?
                    $EX['object'] = NULL;
                    $EX['called_scope'] = NULL;
                    $which = $op1['var'];
                    $EX['fbc'] = $EX['op_array']['funcs'][$which]['name'];
                    break;
                // }}}
                case XC_DO_FCALL_BY_FUNC:
                    $which = $op1['var'];
                    $fname = $EX['op_array']['funcs'][$which]['name'];
                    $args = $this->popargs($EX, $ext);
                    $resvar = $fname . "($args)";
                    break;
                case XC_DO_FCALL:
                    $fname = unquoteName($this->getOpVal($op1, $EX), $EX);
                    $args = $this->popargs($EX, $ext);
                    $resvar = $fname . "($args)";
                    break;
                case XC_DO_FCALL_BY_NAME: // {{{
                    $object = NULL;
                    if ($op2['op_type'] == XC_IS_CONST) {
                        $fname = $this->getOpVal($op2,$EX);
                    } else {
                        $fname = unquoteName($EX['fbc'], $EX);
                    }

                    if (!is_int($EX['object'])) {
                        $object = $EX['object'];
                    }

                    $args = $this->popargs($EX, $ext);
                    $prefix = (isset($object) ? str($object) . '->' : '')
                        . (isset($EX['called_scope']) ? str($EX['called_scope']) . '::' : ''); //StealthDebuger str fix
                    $resvar = $prefix
                        . (!$prefix ? $this->stripNamespace($fname) : (!isset($EX['called_scope']) && (!is_object($EX['fbc']) || get_class($EX['fbc']) != 'Decompiler_Value') ? '{'.$fname.'}' : $fname))
                        . "($args)";
                    unset($args);

                    if (is_int($EX['object'])) {
                        $T[$EX['object']] = $resvar;
                        $resvar = NULL;
                    }
                    list($EX['fbc'], $EX['object'], $EX['called_scope']) = array_pop($EX['arg_types_stack']);
                    break;
                // }}}
                case XC_VERIFY_ABSTRACT_CLASS: // {{{
                    //unset($T[$op1['var']]);
                    break;
                // }}}
                case XC_DECLARE_CLASS:
                case XC_DECLARE_INHERITED_CLASS:
                case XC_DECLARE_INHERITED_CLASS_DELAYED: // {{{
                    $key = $op1['constant'];
                    if (!isset($this->dc['class_table'][$key])) {
                        echo 'class not found: ', $key, 'existing classes are:', "\n";
                        var_dump(array_keys($this->dc['class_table']));
                        exit;
                    }
                    $class = & $this->dc['class_table'][$key];
                    if (!isset($class['name'])) {
                        $class['name'] = unquoteName($this->getOpVal($op2, $EX), $EX);
                    }
                    if ($opc == XC_DECLARE_INHERITED_CLASS || $opc == XC_DECLARE_INHERITED_CLASS_DELAYED) {
                        if (ZEND_ENGINE_2_5) {
                            $ext = (0xffffffff - $ext + 1) / XC_SIZEOF_TEMP_VARIABLE - 1;
                        }
                        else {
                            $ext /= XC_SIZEOF_TEMP_VARIABLE;
                        }
                        if(isset($T[$ext])){
                            $class['parent'] = $T[$ext];
                        }
                        unset($T[$ext]);
                    }
                    else {
                        $class['parent'] = NULL;
                    }

                    for (; ;) {
                        if ($i + 1 <= $range[1]
                            && $opcodes[$i + 1]['opcode'] == XC_ADD_INTERFACE
                            && $opcodes[$i + 1]['op1']['var'] == $res['var']
                        ) {
                            // continue
                        }
                        else {
                            if ($i + 2 <= $range[1]
                                && $opcodes[$i + 2]['opcode'] == XC_ADD_INTERFACE
                                && $opcodes[$i + 2]['op1']['var'] == $res['var']
                                && $opcodes[$i + 1]['opcode'] == XC_FETCH_CLASS
                            ) {
                                // continue
                            }
                            else {
                                break;
                            }
                        }
                        $this->usedOps[XC_ADD_INTERFACE] = TRUE;

                        $fetchop = & $opcodes[$i + 1];
                        $interface = $this->stripNamespace(unquoteName($this->getOpVal($fetchop['op2'], $EX), $EX));
                        $addop = & $opcodes[$i + 2];
                        $class['interfaces'][$addop['extended_value']] = $interface;
                        unset($fetchop, $addop);
                        $i += 2;
                    }
                    $this->dclass($class, $EX['indent']);
                    echo "\n";
                    unset($class);
                    break;
                // }}}
                case XC_INIT_STRING: // {{{
                    $resvar = '';
                    break;
                // }}}
                case XC_ADD_CHAR:
                case XC_ADD_STRING:
                case XC_ADD_VAR: // {{{
                    $op1val = $this->getOpVal($op1, $EX);
                    $op2val = $this->getOpVal($op2, $EX);
                    switch ($opc) {
                        case XC_ADD_CHAR:
                            $op2val = value(chr(str($op2val)));
                            break;
                        case XC_ADD_STRING:
                            break;
                        case XC_ADD_VAR:
                            break;
                    }

                    $sop1 = str($op1val);
                    $sop2 = str($op2val);
                    if ($opc == XC_ADD_VAR) {
                        if (substr_count($sop2,'-') <= 1 && substr_count($sop2,'[') <= 1 && substr_count($sop2,'"') ==0 && substr_count($sop2,'-')+substr_count($sop2,'[') <=1 ) {
                            $sop2 = new Decompiler_Value($sop2,XC_ADD_VAR);
                        } else {
                            $sop2 = new Decompiler_Value('{'.$sop2.'}',XC_ADD_VAR);
                        }
                    }
                    if ($sop1 == '') {
                        $resvar = $sop2;
                    }
                    else {
                        if ($sop2 == '') {
                            $resvar = $sop1;
                        }
                        else {
                            $resvar = new Decompiler_Binop($this,$sop1,XC_CONCAT,$sop2);
                        }
                    }
                    // }}}
                    break;
                case XC_PRINT: // {{{
                    $op1val = $this->getOpVal($op1, $EX);
                    $resvar = "print(" . str($op1val) . ")";
                    break;
                // }}}
                case XC_ECHO: // {{{
                    $op1val = $this->getOpVal($op1, $EX);
                    $resvar = "echo " . str($op1val);
                    break;
                // }}}
                case XC_EXIT: // {{{
                    $op1val = $this->getOpVal($op1, $EX);
                    $resvar = "exit(" . str($op1val) . ")"; //sidxx55 not empty DIE fix code
                    if ( $opcodes[$i + 1]['opcode'] == XC_BOOL
                        && $opcodes[$i + 1]['op1']['op_type'] == XC_IS_CONST
                        && $opcodes[$i + 1]['op1']['constant'] === TRUE
                    ) {
                        $istmpres = TRUE;
                    }
                    break;
                // }}}
                case XC_INIT_ARRAY:
                case XC_ADD_ARRAY_ELEMENT: // {{{
                    $rvalue = $this->getOpVal($op1, $EX, TRUE);
                    // РµСЃР»Рё $ext == true, С‚Рѕ РїР°СЂР°РјРµС‚СЂ РїРµСЂРµРґР°РµС‚СЃСЏ РєР°Рє СЃСЃС‹Р»РєР°
                    if ($ext) $rvalue = '&'.str($rvalue);
                    if ($opc == XC_ADD_ARRAY_ELEMENT) {
                        $assoc = $this->getOpVal($op2, $EX);
                        if (isset($assoc)) {
                            $curResVar->value[] = array($assoc, $rvalue);
                        }
                        else {
                            $curResVar->value[] = array(NULL, $rvalue);
                        }
                    }
                    else {
                        if ($opc == XC_INIT_ARRAY) {
                            $resvar = new Decompiler_Array();
                            if (!isset($rvalue)) {
                                continue;
                            }
                        }

                        $assoc = $this->getOpVal($op2, $EX);
                        if (isset($assoc)) {
                            $resvar->value[] = array($assoc, $rvalue);
                        }
                        else {
                            $resvar->value[] = array(NULL, $rvalue);
                        }
                    }
                    break;
                // }}}
                case XC_QM_ASSIGN:
                case XC_QM_ASSIGN_VAR: // {{{
                    if (isset($curResVar) && is_a($curResVar, 'Decompiler_Binop')) {
                        $curResVar->op2 = $this->getOpVal($op1, $EX);
                    }
                    else {
                        $resvar = $this->getOpVal($op1, $EX);
                    }
                    break;
                // }}}
                case XC_BOOL: // {{{
                    if ( $opcodes[$i - 1]['opcode'] == XC_EXIT
                        && $op['op1']['op_type'] == XC_IS_CONST
                        && $op['op1']['constant'] === TRUE
                    ) {
                        $resvar = isset($lastresvar) ? $lastresvar : '';
                    } else {
                        $resvar = /*'(bool) ' .*/ $this->getOpVal($op1, $EX);
                    }
                    break;
                // }}}
                case XC_GENERATOR_RETURN:
                case XC_SEPARATE:
                case XC_RETURN_BY_REF:
                case XC_RETURN: // {{{
                    $resvar = "return " . str($this->getOpVal($op1, $EX));
                    break;
                // }}}
                case XC_INCLUDE_OR_EVAL: // {{{
                    $type = ZEND_ENGINE_2_4 ? $ext : $op2['var']; // hack
                    $keyword = $this->includeTypes[$type];
                    $resvar = "$keyword " . str($this->getOpVal($op1, $EX));
                    break;
                // }}}
                case XC_FE_RESET: // {{{
                    $resvar = $this->getOpVal($op1, $EX);
                    break;
                // }}}
                case XC_FE_FETCH: // {{{
                    $op['fe_src'] = $this->getOpVal($op1, $EX, TRUE);
                    $fe = new Decompiler_ForeachBox($op);
                    $fe->iskey = FALSE;
                    $T[$res['var']] = $fe;

                    ++$i;
                    if (($ext & ZEND_FE_FETCH_WITH_KEY)) {
                        $fe = new Decompiler_ForeachBox($op);
                        $fe->iskey = TRUE;

                        $res = $opcodes[$i]['result'];
                        $T[$res['var']] = $fe;
                    }
                    break;
                // }}}
                case XC_YIELD: // {{{
                    $resvar = new Decompiler_Binop($this, $this->getOpVal($op1, $EX), XC_YIELD, NULL);
                    break;
                // }}}
                case XC_SWITCH_FREE: // {{{
                    break;
                // }}}
                case XC_FREE: // {{{
                    $free = $T[$op1['var']];
                    if (!is_a($free, 'Decompiler_Array') && !is_a($free, 'Decompiler_Box')) {
                        $op['php'] = is_object($free) ? $free : $this->unquote($free, '(', ')');
                        $lastphpop = & $op;
                    }
                    unset($T[$op1['var']], $free);
                    break;
                // }}}
                case XC_JMP_NO_CTOR:
                    break;
                #case XC_JMP_SET: // ?:
                #   $resvar = new Decompiler_Binop($this, $this->getOpVal($op1, $EX), XC_JMP_SET, NULL);
                #   break;
                case XC_JMPZ_EX: // and
                case XC_JMPNZ_EX: // or
                    $resvar = $this->getOpVal($op1, $EX);
                    break;

                case XC_JMPNZ: // while
                case XC_JMPZNZ: // for
                case XC_JMPZ: // {{{
                    break;
                // }}}
                case XC_CONT:
                case XC_BRK:
                    $resvar = $opc == XC_CONT ? 'continue' : 'break';
                    $count = str($this->getOpVal($op2, $EX));
                    if ($count != '1') {
                        $resvar .= ' ' . $count;
                    }
                    break;
                case XC_GOTO:
                    $resvar = 'goto label' . $op['op1']['var'];
                    $istmpres = FALSE;
                    break;

                case XC_JMP: // {{{
                    break;
                // }}}
                case XC_CASE:
                    // $switchValue = $this->getOpVal($op1, $EX);
                    $caseValue = $this->getOpVal($op2, $EX);
                    $resvar = $caseValue;
                    break;
                case XC_RECV_INIT:
                case XC_RECV:
                    $offset = $this->getOpVal($op1, $EX);
                    $lvalue = $this->getOpVal($op['result'], $EX);
                    if ($opc == XC_RECV_INIT) {
                        $default = value($op['op2']['constant']);
                    }
                    else {
                        $default = NULL;
                    }
                    $EX['recvs'][str($offset)] = array($lvalue, $default);
                    break;
                case XC_POST_DEC:
                case XC_POST_INC:
                case XC_POST_DEC_OBJ:
                case XC_POST_INC_OBJ:
                case XC_PRE_DEC:
                case XC_PRE_INC:
                case XC_PRE_DEC_OBJ:
                case XC_PRE_INC_OBJ: // {{{
                    $flags = array_flip(explode('_', $opname));
                    if (isset($flags['OBJ'])) {
                        $resvar = str($this->getOpVal($op1, $EX)) . '->' . unquoteVariableName($this->getOpVal($op2, $EX));
                    }
                    else {
                        $resvar = $this->getOpVal($op1, $EX);
                    }

                    if (is_object($resvar)) $resvar = str($resvar);
                    $opstr = isset($flags['DEC']) ? '--' : '++';
                    if (isset($flags['POST'])) {
                        $resvar .= $opstr;
                    }
                    else {
                        $resvar = "$opstr$resvar";
                    }
                    break;
                // }}}

                case XC_BEGIN_SILENCE: // {{{
                    $EX['silence']++;
                    break;
                // }}}
                case XC_END_SILENCE: // {{{
                    $EX['silence']--;
                    $lastresvar = '@' . (isset($lastresvar) ? str($lastresvar, $EX) : '');
                    break;
                // }}}
                case XC_CAST: // {{{
                    $type = $ext;
                    static $type2cast = array(
                        IS_LONG => '(int)',
                        IS_DOUBLE => '(double)',
                        IS_STRING => '(string)',
                        IS_ARRAY => '(array)',
                        IS_OBJECT => '(object)',
                        IS_BOOL => '(bool)',
                        IS_NULL => '(unset)',
                    );
                    assert(isset($type2cast[$type]));
                    $cast = $type2cast[$type];
                    $resvar = str($cast) . ' ' . str($this->getOpVal($op1, $EX)); //StealthDebuger string fix
                    break;
                // }}}
                case XC_EXT_STMT:
                case XC_EXT_FCALL_BEGIN:
                case XC_EXT_FCALL_END:
                case XC_EXT_NOP:
                case XC_INIT_CTOR_CALL:
                    break;
                case XC_DECLARE_FUNCTION:
                    $this->dfunction($this->dc['function_table'][$op1['constant']], $EX['indent']);
                    break;
                case XC_DECLARE_LAMBDA_FUNCTION: // {{{
                    ob_start();
                    $this->dfunction($this->dc['function_table'][$op1['constant']], $EX['indent']);
                    $resvar = ob_get_clean();
                    $istmpres = TRUE;
                    break;
                // }}}
                case XC_DECLARE_CONST:
                    $name = $this->stripNamespace(unquoteName($this->getOpVal($op1, $EX), $EX));
                    $value = str($this->getOpVal($op2, $EX));
                    $resvar = 'const ' . $name . ' = ' . $value;
                    break;
                case XC_DECLARE_FUNCTION_OR_CLASS:
                    /* always removed by compiler */
                    break;
                case XC_TICKS:
                    $lastphpop['ticks'] = $this->getOpVal($op1, $EX);
                    // $EX['tickschanged'] = true;
                    break;
                case XC_RAISE_ABSTRACT_ERROR:
                    // abstract function body is empty, don't need this code
                    break;
                case XC_USER_OPCODE:
                    echo '// ZEND_USER_OPCODE, impossible to decompile';
                    break;
                case XC_OP_DATA:
                    break;
                default: // {{{
                    $call = array(&$this, $opname);
                    if (is_callable($call)) {
                        $this->usedOps[$opc] = TRUE;
                        $this->{$opname}($op, $EX);
                    }
                    else {
                        if (isset($this->binops[$opc])) { // {{{ //dima2k this fix
                            $this->usedOps[$opc] = TRUE;
                            $op1val = $this->getOpVal($op1, $EX);
                            $op2val = $this->getOpVal($op2, $EX);
                            if ($opcodes[$i + 1]['opcode'] == XC_OP_DATA) {
                                ++$i;
                                if ($opcodes[$i]['op2']['op_type'] == 4) {
                                    $lvalue = str($op1val) . '[' . str($op2val) . ']';
                                }
                                else {
                                    $lvalue = str($op1val) . "this->" . unquoteVariableName($op2val);
                                }
                                $rvalue = $this->getOpVal($opcodes[$i]['op1'], $EX);
                                $op1val = $lvalue;
                                $op2val = $rvalue;
                            }
                            $rvalue = new Decompiler_Binop($this, $op1val, $opc, $op2val);
                            $resvar = $rvalue;
                            // }}}
                        }
                        else {
                            if (isset($this->unaryops[$opc])) { // {{{
                                $this->usedOps[$opc] = TRUE;
                                $op1val = $this->getOpVal($op1, $EX);
                                $myop = $this->unaryops[$opc];
                                $rvalue = $myop . str($op1val);
                                $resvar = $rvalue;
                                // }}}
                            }
                            else {
                                $notHandled = TRUE;
                            }
                        }
                    }
                // }}}
            }
            if ($notHandled) {
                echo "\x1B[31m * TODO ", $opname, "\x1B[0m\n";
            }
            else {
                $this->usedOps[$opc] = TRUE;
            }

            // РћРїСЂРµРґРµР»РµРЅРёРµ РїРµСЂРµРјРµРЅРЅРѕР№ РґР»СЏ РёРЅРёС†РёР°Р»РёР·Р°С†РёРё for
            if (isset($resvar) && !empty($opcodes[$i+1]['jmpins']) && $opcodes[$opcodes[$i+1]['jmpins'][0]]['opcode'] == XC_JMP) {
                $firstOp = & $opcodes[$i+1];
                $lastOp = & $opcodes[$firstOp['jmpins'][0]];
                if (!empty($lastOp['jmpouts']) && $lastOp['jmpouts'][0] <= $firstOp['jmpins'][0]
                    && !empty($opcodes[$firstOp['jmpins'][0] + 1]['jmpins']) && $opcodes[$opcodes[$firstOp['jmpins'][0] + 1]['jmpins'][0]]['opcode'] == XC_JMPZNZ
                ) {
                    $EX['init_for'] = $resvar;
                    unset($resvar);
                }
            }

            if (isset($resvar)) {
                if ($istmpres) {
                    $T[$res['var']] = $resvar;
                    $lastresvar = & $T[$res['var']];
                }
                else {
                    $op['php'] = $resvar;
                    $lastphpop = & $op;
                    $lastresvar = & $op['php'];
                }
            }
        }
        return $T;
    }

    // }}}
    function unquote($str, $st, $ed) // {{{
    {
        $l1 = strlen($st);
        $l2 = strlen($ed);
        if (substr($str, 0, $l1) === $st && substr($str, -$l2) === $ed) {
            $str = substr($str, $l1, -$l2);
        }
        return $str;
    }

    // }}}
    function popargs(&$EX, $n) // {{{
    {
        $args = array();
        for ($i = 0; $i < $n; $i++) {
            $a = array_pop($EX['argstack']);
            if (is_array($a)) {
                array_unshift($args, foldToCode($a, $EX));
            }
            else {
                array_unshift($args, $a);
            }
        }
        return implode(', ', $args);
    }

    // }}}
    function dumpop($op, &$EX) // {{{
    {
        assert('isset($op)');
        $op1 = $op['op1'];
        $op2 = $op['op2'];
        $d = array(xcache_get_opcode($op['opcode']), $op['opcode']);

        foreach (array('op1' => '1:', 'op2' => '2:', 'result' => '>') as $k => $kk) {
            switch ($op[$k]['op_type']) {
                case XC_IS_UNUSED:
                    $d[$kk] = 'U:' . $op[$k]['opline_num'];
                    break;

                case XC_IS_VAR:
                    $d[$kk] = '$' . $op[$k]['var'];
                    if ($k != 'result') {
                        $d[$kk] .= ':' . str($this->getOpVal($op[$k], $EX));
                    }
                    break;

                case XC_IS_TMP_VAR:
                    $d[$kk] = '#' . $op[$k]['var'];
                    if ($k != 'result') {
                        $d[$kk] .= ':' . str($this->getOpVal($op[$k], $EX));
                    }
                    break;

                case XC_IS_CV:
                    $d[$kk] = $this->getOpVal($op[$k], $EX);
                    break;

                default:
                    if ($k == 'result') {
                        var_dump($op);
                        assert(0);
                        exit;
                    }
                    else {
                        $d[$kk] = $this->getOpVal($op[$k], $EX);
                    }
            }
        }
        $d[';'] = $op['extended_value'];
        if (!empty($op['jmpouts'])) {
            $d['>>'] = implode(',', $op['jmpouts']);
        }
        if (!empty($op['jmpins'])) {
            $d['<<'] = implode(',', $op['jmpins']);
        }

        foreach ($d as $k => $v) {
            echo is_int($k) ? '' : $k, str($v), "\t";
        }
        echo PHP_EOL;
    }

    // }}}
    function dumpRange(&$EX, $range) // {{{
    {
        for ($i = $range[0]; $i <= $range[1]; ++$i) {
            echo $EX['indent'], $i, "\t";
            $this->dumpop($EX['opcodes'][$i], $EX);
        }
        echo $EX['indent'], "==", PHP_EOL;
    }

    // }}}
    function dargs(&$EX) // {{{
    {
        $op_array = & $EX['op_array'];

        if (isset($op_array['num_args'])) {
            $c = $op_array['num_args'];
        }
        else {
            if (!empty($op_array['arg_types'])) {
                $c = count($op_array['arg_types']);
            }
            else {
                // php4
                $c = count($EX['recvs']);
            }
        }

        $refrest = FALSE;
        for ($i = 0; $i < $c; $i++) {
            if ($i) {
                echo ', ';
            }
            if (isset($EX['recvs'][$i + 1])) $arg = $EX['recvs'][$i + 1]; else $arg = array(); // fix alert for $isInterface || $isAbstractMethod
            if (isset($op_array['arg_info'])) {
                $ai = $op_array['arg_info'][$i];
                if (!empty($ai['class_name'])) {
                    echo $this->stripNamespace($ai['class_name']), ' ';
                    if (!ZEND_ENGINE_2_2 && $ai['allow_null']) {
                        echo 'or NULL ';
                    }
                }
                else {
                    if (!empty($ai['array_type_hint'])) {
                        echo 'array ';
                        if (!ZEND_ENGINE_2_2 && $ai['allow_null']) {
                            echo 'or NULL ';
                        }
                    }
                }
                if ($ai['pass_by_reference']) {
                    echo '&';
                }
                printf("\$%s", $ai['name']);
            }
            else {
                if ($refrest) {
                    echo '&';
                }
                else {
                    if (!empty($op_array['arg_types']) && isset($op_array['arg_types'][$i])) {
                        switch ($op_array['arg_types'][$i]) {
                            case BYREF_FORCE_REST:
                                $refrest = TRUE;
                            /* fall */
                            case BYREF_FORCE:
                                echo '&';
                                break;

                            case BYREF_NONE:
                            case BYREF_ALLOW:
                                break;
                            default:
                                assert(0);
                        }
                    }
                }
                echo str($arg[0], $EX);
            }
            if (isset($arg[1])) {
                echo ' = ', str($arg[1], $EX);
            }
        }
    }

    // }}}
    function duses(&$EX) // {{{
    {
        if ($EX['uses']) {
            echo ' use(', implode(', ', $EX['uses']), ')';
        }
    }

    // }}}
    function dfunction($func, $indent = '', $decorations = array(), $nobody = FALSE) // {{{
    {
        $this->detectNamespace($func['op_array']['function_name']);

        if ($nobody) {
            $EX = array();
            $EX['op_array'] = & $func['op_array'];
            $EX['recvs'] = array();
            $EX['uses'] = array();
        }
        else {
            ob_start();
            $EX = & $this->dop_array($func['op_array'], $indent . INDENT);
            $body = ob_get_clean();
        }

        $functionName = $this->stripNamespace($func['op_array']['function_name']);
        $isExpression = FALSE;
        if ($functionName == '{closure}') {
            $functionName = '';
            $isExpression = TRUE;
        }
        echo $isExpression ? '' : $indent;
        if ($decorations) {
            echo implode(' ', $decorations), ' ';
        }
        echo 'function', $functionName ? ' ' . $functionName : '', '(';
        $this->dargs($EX);
        echo ")";
        $this->duses($EX);
        if ($nobody) {
            echo ";\n";
        }
        else {
            if (!$isExpression) {
                echo "\n";
                echo $indent, "{\n";
            }
            else {
                echo " {\n";
            }

            echo $body;
            echo "$indent}";
            if (!$isExpression) {
                echo "\n";
            }
        }
    }

    // }}}


    function &findClassByName(&$ct,$name) {
        foreach (array_keys($ct) as $n) {
            if ($ct[$n]['name'] == $name) return $ct[$n];
        }
        $r = NULL;
        return $r;
    }

    function &findFunctionByName(&$ft,$name) {
        foreach (array_keys($ft) as $n) {
            if ($ft[$n]['op_array']['function_name'] == $name) return $ft[$n];
        }
        $r = NULL;
        return $r;
    }

    // Поиск аналогичной функции в родительском классе
    // Если найдена - вовзращает TRUE
    function functionExistInParent(&$parentclass, &$fun) {
        if (is_array($parentclass) && is_array($fun)) {
            $fn = $this->findFunctionByName($parentclass['function_table'],$fun['op_array']['function_name']);
            if (is_array($fn) && $fn['op_array']['line_start'] == $fun['op_array']['line_start'] && $fn['op_array']['fn_flags'] == $fun['op_array']['fn_flags']) return true;
        }
        return false;
    }

    function dclass($class, $indent = '',&$ct = NULL) // {{{
    {
        $this->detectNamespace($class['name']);
        if (!$ct) $ct = &$GLOBALS['class_table'];

        // {{{ class decl
        if (!empty($class['doc_comment'])) {
            echo $indent;
            echo $class['doc_comment'];
            echo "\n";
        }
        $isInterface = FALSE;
        $decorations = array();
        if (!empty($class['ce_flags'])) {
            if ($class['ce_flags'] & ZEND_ACC_INTERFACE) {
                $isInterface = TRUE;
            }
            else {
                if ($class['ce_flags'] & ZEND_ACC_IMPLICIT_ABSTRACT_CLASS) {
                    $decorations[] = "abstract";
                }
                if ($class['ce_flags'] & ZEND_ACC_FINAL_CLASS) {
                    $decorations[] = "final";
                }
            }
        }

        echo $indent;
        if ($decorations) {
            echo implode(' ', $decorations), ' ';
        }
        echo $isInterface ? 'interface ' : 'class ', $this->stripNamespace($class['name']);
        if ($class['parent']) {
            echo ' extends ', $this->stripNamespace($class['parent']);
        }
        /* TODO */
        if (!empty($class['interfaces'])) {
            echo ' implements ';
            echo implode(', ', $class['interfaces']);
        }
        echo "\n";
        echo $indent, "{";
        // }}}
        $newindent = INDENT . $indent;
        // {{{ const, static
        foreach (array(
                     'constants_table' => 'const '
                 ,
                     'static_members' => 'static $'
                 ) as $type => $prefix) {
            if (!empty($class[$type])) {
                echo "\n";
                foreach ($class[$type] as $name => $v) {
                    if (isset($class['parent'])) {
                        $p = $this->findClassByName($ct,$class['parent']);
                        if (isset($p[$type]) && isset($p[$type][$name]) && $p[$type][$name] == $v) continue;
                    }
                    echo $newindent;
                    echo $prefix, $name, ' = ';
                    echo str(value($v), $newindent);
                    echo ";\n";
                }
            }
        }
        // }}}
        // {{{ properties
        $member_variables = isset($class['properties_info']) ? $class['properties_info'] : ($class['default_static_members'] + $class['default_properties']);
        if ($member_variables) {
            echo "\n";
            $infos = !empty($class['properties_info']) ? $class['properties_info'] : NULL;
            foreach ($member_variables as $name => $dummy) {
                $info = (isset($infos) && isset($infos[$name])) ? $infos[$name] : NULL;
                if (isset($info)) {
                    if (!empty($info['doc_comment'])) {
                        echo $newindent;
                        echo $info['doc_comment'];
                        echo "\n";
                    }
                }

                // сокрытие переменных родительского класса в дочернем
                if (isset($info['ce']) && $info['ce'] != $class['name'] ) continue;

                $static = FALSE;
                if (isset($info)) {
                    if ($info['flags'] & ZEND_ACC_STATIC) {
                        $static = TRUE;
                    }
                }
                else {
                    if (isset($class['default_static_members'][$name])) {
                        $static = TRUE;
                    }
                }

                if ($static) {
                    echo $newindent . "static ";
                }

                // shadow of parent's private method/property dima2k fix
                $mangled = FALSE;
                if (!ZEND_ENGINE_2) {
                    echo $newindent . 'var ';
                }
                else {
                    if (!isset($info)) {
                        echo $newindent . 'public ';
                    }
                    else {
                        if ($info['flags'] & ZEND_ACC_SHADOW) {
                            continue;
                        }
                        echo $newindent;
                        switch ($info['flags'] & ZEND_ACC_PPP_MASK) {
                            case ZEND_ACC_PUBLIC:
                                echo "public ";
                                break;
                            case ZEND_ACC_PRIVATE:
                                echo "private ";
                                $mangled = TRUE;
                                break;
                            case ZEND_ACC_PROTECTED:
                                echo "protected ";
                                $mangled = TRUE;
                                break;
                        }
                    }
                }

                echo '$', $name;

                if (isset($info['offset'])) {
                    $value = $class[$static ? 'default_static_members_table' : 'default_properties_table'][$info['offset']];
                }
                else {
                    $key = isset($info) ? $info['name'] . ($mangled ? "\000" : "") : $name;
                    $value = $class[$static ? 'default_static_members' : 'default_properties'][$key];
                }
                if (isset($value)) {
                    echo ' = ';
                    echo str(value($value), $newindent);
                }
                echo ";\n";
            }
        }
        // }}}
        // {{{ function_table
        if (isset($class['function_table'])) {
            foreach ($class['function_table'] as $func) {
                if (!isset($func['scope']) || $func['scope'] == $class['name']) {
                    // TODO: всеравно возможен повторый вывод родителских функций если классы с функциями определяются одной длинной строкой
                    if(!isset($class['parent']) || !$this->functionExistInParent($this->findClassByName($ct, $class['parent']),$func)) {
                        echo "\n";
                        $opa = $func['op_array'];
                        if (!empty($opa['doc_comment'])) {
                            echo $newindent;
                            echo $opa['doc_comment'];
                            echo "\n";
                        }
                        $isAbstractMethod = FALSE;
                        $decorations = array();
                        if (isset($opa['fn_flags'])) {
                            if (($opa['fn_flags'] & ZEND_ACC_ABSTRACT) && !$isInterface) {
                                $decorations[] = "abstract";
                                $isAbstractMethod = TRUE;
                            }
                            if ($opa['fn_flags'] & ZEND_ACC_FINAL) {
                                $decorations[] = "final";
                            }
                            if ($opa['fn_flags'] & ZEND_ACC_STATIC) {
                                $decorations[] = "static";
                            }

                            switch ($opa['fn_flags'] & ZEND_ACC_PPP_MASK) {
                                case ZEND_ACC_PUBLIC:
                                    $decorations[] = "public";
                                    break;
                                case ZEND_ACC_PRIVATE:
                                    $decorations[] = "private";
                                    break;
                                case ZEND_ACC_PROTECTED:
                                    $decorations[] = "protected";
                                    break;
                                default:
                                    $decorations[] = "<visibility error>";
                                    break;
                            }
                        }
                        $this->dfunction($func, $newindent, $decorations, $isInterface || $isAbstractMethod);
                        if ($opa['function_name'] == 'Decompiler') {
                            //exit;
                        }
                    }
                }
            }
        }
        // }}}
        echo $indent, "}\n";
    }

    // }}}
    function decompileString($string) // {{{
    {
        $this->dc = xcache_dasm_string($string);
        if ($this->dc === FALSE) {
            echo "error compling string\n";
            return FALSE;
        }
    }

    // }}}
    function decompileFile($file) // {{{
    {
        if (file_exists($file)) $this->dc = xcache_dasm_file($file);
        if ($this->dc === false) {
            echo "error compling $file\n";
            return false;
        }
        $GLOBALS['_CURRENT_FILE'] = $this->dc['op_array']['filename'];
    }

    // }}}
    function decompileDasm($content) // {{{
    {
        $this->dc = $content;
    }

    // }}}
    function output() // {{{
    {
        echo "<?" . "php\n\n";
        $GLOBALS['class_table'] = &$this->dc['class_table'];

        /*    // deobfuscate function names
              foreach ($this->dc['class_table'] as $key => &$class) {
                $fc = array();
                foreach ($class['function_table'] as $fname => &$fdata) {
                  $nname = fixFunctionName($fname);
                  if ($fname != $nname) {
                    $fc[$nname] = &$fdata;
                    $fdata['op_array']['function_name'] = $nname;
                  } else $fc[$fname] = &$fdata;
                  unset($fdata);
                }

                $class['function_table'] = &$fc;
                unset($fc);
              }

            $fc = array();
            foreach ($this->dc['function_table'] as $fname => &$fdata) {
              $nname = fixFunctionName($fname);
              if ($fname != $nname) {
                $fc[$nname] = &$fdata;
                $fdata['op_array']['function_name'] .= $nname;
              } else $fc[$fname] = &$fdata;
              unset($fdata);

            }
            $this->dc['function_table']  = &$fc;
            unset($fc);*/


        foreach ($this->dc['class_table'] as $key => $class) {
            if ($key{0} != "\0") {
                $this->dclass($class,'',$this->dc['class_table']);
                echo "\n";
            }
        }

        foreach ($this->dc['function_table'] as $key => $func) {
            if ($key{0} != "\0") {
                $this->dfunction($func);
                echo "\n";
            }
        }

        $this->dop_array($this->dc['op_array']);
        echo "\n?" . ">\n";

        if (!empty($this->test)) {
            $this->outputUnusedOp();
        }
        return true;
    }
    // }}}
    function outputUnusedOp() // {{{
    {
        for ($i = 0; $opname = xcache_get_opcode($i); $i++) {
            if ($opname == 'UNDEF') {
                continue;
            }

            if (!isset($this->usedOps[$i])) {
                echo "not covered opcode ", $opname, PHP_EOL;
            }
        }
    }
    // }}}
}

// {{{ defines
define('ZEND_ENGINE_2_6', PHP_VERSION >= "5.6");
define('ZEND_ENGINE_2_5', ZEND_ENGINE_2_6 || PHP_VERSION >= "5.5.");
define('ZEND_ENGINE_2_4', ZEND_ENGINE_2_5 || PHP_VERSION >= "5.4.");
define('ZEND_ENGINE_2_3', ZEND_ENGINE_2_4 || PHP_VERSION >= "5.3.");
define('ZEND_ENGINE_2_2', ZEND_ENGINE_2_3 || PHP_VERSION >= "5.2.");
define('ZEND_ENGINE_2_1', ZEND_ENGINE_2_2 || PHP_VERSION >= "5.1.");
define('ZEND_ENGINE_2',   ZEND_ENGINE_2_1 || PHP_VERSION >= "5.0.");

define('ZEND_ACC_STATIC',         0x01);
define('ZEND_ACC_ABSTRACT',       0x02);
define('ZEND_ACC_FINAL',          0x04);
define('ZEND_ACC_IMPLEMENTED_ABSTRACT',       0x08);

define('ZEND_ACC_IMPLICIT_ABSTRACT_CLASS',    0x10);
define('ZEND_ACC_EXPLICIT_ABSTRACT_CLASS',    0x20);
define('ZEND_ACC_FINAL_CLASS',                0x40);
define('ZEND_ACC_INTERFACE',                  0x80);
if (ZEND_ENGINE_2_4) {
    define('ZEND_ACC_TRAIT',                  0x120);
}
define('ZEND_ACC_PUBLIC',     0x100);
define('ZEND_ACC_PROTECTED',  0x200);
define('ZEND_ACC_PRIVATE',    0x400);
define('ZEND_ACC_PPP_MASK',  (ZEND_ACC_PUBLIC | ZEND_ACC_PROTECTED | ZEND_ACC_PRIVATE));

define('ZEND_ACC_CHANGED',    0x800);
define('ZEND_ACC_IMPLICIT_PUBLIC',    0x1000);

define('ZEND_ACC_CTOR',       0x2000);
define('ZEND_ACC_DTOR',       0x4000);
define('ZEND_ACC_CLONE',      0x8000);

define('ZEND_ACC_ALLOW_STATIC',   0x10000);

define('ZEND_ACC_SHADOW', 0x20000); //dima2k fix ERROR:原版0x2000

if (ZEND_ENGINE_2_4) {
    define('ZEND_FETCH_GLOBAL',           0x00000000);
    define('ZEND_FETCH_LOCAL',            0x10000000);
    define('ZEND_FETCH_STATIC',           0x20000000);
    define('ZEND_FETCH_STATIC_MEMBER',    0x30000000);
    define('ZEND_FETCH_GLOBAL_LOCK',      0x40000000);
    define('ZEND_FETCH_LEXICAL',          0x50000000);

    define('ZEND_FETCH_TYPE_MASK',        0x70000000);

    define('ZEND_FETCH_STANDARD',         0x00000000);
    define('ZEND_FETCH_ADD_LOCK',         0x08000000);
    define('ZEND_FETCH_MAKE_REF',         0x04000000);
}
else {
    define('ZEND_FETCH_GLOBAL',           0);
    define('ZEND_FETCH_LOCAL',            1);
    define('ZEND_FETCH_STATIC',           2);
    define('ZEND_FETCH_STATIC_MEMBER',    3);
    define('ZEND_FETCH_GLOBAL_LOCK',      4);

    define('ZEND_FETCH_STANDARD',         0);
    define('ZEND_FETCH_ADD_LOCK',         1);
}
define('XC_FETCH_PROPERTY', 10);

if (ZEND_ENGINE_2_4) {
    define('ZEND_ISSET',                  0x02000000);
    define('ZEND_ISEMPTY',                0x01000000);
    define('ZEND_ISSET_ISEMPTY_MASK',     (ZEND_ISSET | ZEND_ISEMPTY));
    define('ZEND_QUICK_SET',              0x00800000);
}
else {
    define('ZEND_ISSET',                  (1<<0));
    define('ZEND_ISEMPTY',                (1<<1));

    define('ZEND_ISSET_ISEMPTY_MASK',     (ZEND_ISSET | ZEND_ISEMPTY));
}

define('ZEND_FETCH_CLASS_DEFAULT',    0);
define('ZEND_FETCH_CLASS_SELF',       1);
define('ZEND_FETCH_CLASS_PARENT',     2);
define('ZEND_FETCH_CLASS_MAIN',       3);
define('ZEND_FETCH_CLASS_GLOBAL',     4);
define('ZEND_FETCH_CLASS_AUTO',       5);
define('ZEND_FETCH_CLASS_INTERFACE',  6);
define('ZEND_FETCH_CLASS_STATIC',     7);
if (ZEND_ENGINE_2_4) {
    define('ZEND_FETCH_CLASS_TRAIT',     14);
}
if (ZEND_ENGINE_2_3) {
    define('ZEND_FETCH_CLASS_MASK',     0xF);
}

define('ZEND_EVAL',               (1<<0));
define('ZEND_INCLUDE',            (1<<1));
define('ZEND_INCLUDE_ONCE',       (1<<2));
define('ZEND_REQUIRE',            (1<<3));
define('ZEND_REQUIRE_ONCE',       (1<<4));

if (ZEND_ENGINE_2_4) {
    define('EXT_TYPE_UNUSED',     (1<<5));
}
else {
    define('EXT_TYPE_UNUSED',     (1<<0));
}

if (ZEND_ENGINE_2_1) {
    define('ZEND_FE_FETCH_BYREF',     1);
    define('ZEND_FE_FETCH_WITH_KEY',  2);
}
else {
    define('ZEND_UNSET_DIM',          1);
    define('ZEND_UNSET_OBJ',          2);
}

define('ZEND_MEMBER_FUNC_CALL',   1<<0);
define('ZEND_CTOR_CALL',          1<<1);

define('ZEND_ARG_SEND_BY_REF',        (1<<0));
define('ZEND_ARG_COMPILE_TIME_BOUND', (1<<1));
define('ZEND_ARG_SEND_FUNCTION',      (1<<2));

define('BYREF_NONE',       0);
define('BYREF_FORCE',      1);
define('BYREF_ALLOW',      2);
define('BYREF_FORCE_REST', 3);
define('IS_NULL',     0);
define('IS_LONG',     1);
define('IS_DOUBLE',   2);
define('IS_BOOL',     ZEND_ENGINE_2_1 ? 3 : 6);
define('IS_ARRAY',    4);
define('IS_OBJECT',   5);
define('IS_STRING',   ZEND_ENGINE_2_1 ? 6 : 3);
define('IS_RESOURCE', 7);
define('IS_CONSTANT', 8);
if (ZEND_ENGINE_2_6) {
    define('IS_CONSTANT_ARRAY', -1);
    define('IS_CONSTANT_AST', 9);
}
else {
    define('IS_CONSTANT_ARRAY', 9);
}
if (ZEND_ENGINE_2_4) {
    define('IS_CALLABLE', 10);
}
/* Ugly hack to support constants as static array indices */
define('IS_CONSTANT_TYPE_MASK',   0x0f);
define('IS_CONSTANT_UNQUALIFIED', 0x10);
define('IS_CONSTANT_INDEX',       0x80);
define('IS_LEXICAL_VAR',          0x20);
define('IS_LEXICAL_REF',          0x40);

if (ZEND_ENGINE_2_6) {
    define('ZEND_CONST',          256);
    define('ZEND_BOOL_AND',       256 + 1);
    define('ZEND_BOOL_OR',        256 + 2);
    define('ZEND_SELECT',         256 + 3);
    define('ZEND_UNARY_PLUS',     256 + 4);
    define('ZEND_UNARY_MINUS',    256 + 5);
}

@define('XC_IS_CV', 16);
if (!defined("PHP_EOL")) {
    define("PHP_EOL", "\n");
}

/*
if (preg_match_all('!XC_[A-Z_]+!', file_get_contents(__FILE__), $ms)) {
	$verdiff = array();
	foreach ($ms[0] as $k) {
		if (!defined($k)) {
			$verdiff[$k] = -1;
			define($k, -1);
		}
	}
	var_export($verdiff);
}
/*/
foreach (array (
             'XC_ADD_INTERFACE' => -1,
             'XC_ASSIGN_DIM' => -1,
             'XC_ASSIGN_OBJ' => -1,
             'XC_ASSIGN_POW' => -1,
             'XC_CATCH' => -1,
             'XC_CLONE' => -1,
             'XC_DECLARE_CLASS' => -1,
             'XC_DECLARE_CONST' => -1,
             'XC_DECLARE_FUNCTION' => -1,
             'XC_DECLARE_FUNCTION_OR_CLASS' => -1,
             'XC_DECLARE_INHERITED_CLASS' => -1,
             'XC_DECLARE_INHERITED_CLASS_DELAYED' => -1,
             'XC_DECLARE_LAMBDA_FUNCTION' => -1,
             'XC_DO_FCALL_BY_FUNC' => -1,
             'XC_FETCH_CLASS' => -1,
             'XC_GENERATOR_RETURN' => -1,
             'XC_GOTO' => -1,
             'XC_HANDLE_EXCEPTION' => -1,
             'XC_INIT_CTOR_CALL' => -1,
             'XC_INIT_FCALL_BY_FUNC' => -1,
             'XC_INIT_METHOD_CALL' => -1,
             'XC_INIT_NS_FCALL_BY_NAME' => -1,
             'XC_INIT_STATIC_METHOD_CALL' => -1,
             'XC_INSTANCEOF' => -1,
             'XC_ISSET_ISEMPTY' => -1,
             'XC_ISSET_ISEMPTY_DIM_OBJ' => -1,
             'XC_ISSET_ISEMPTY_PROP_OBJ' => -1,
             'XC_ISSET_ISEMPTY_VAR' => -1,
             'XC_JMP_NO_CTOR' => -1,
             'XC_JMP_SET' => -1,
             'XC_JMP_SET_VAR' => -1,
             'XC_OP_DATA' => -1,
             'XC_POST_DEC_OBJ' => -1,
             'XC_POST_INC_OBJ' => -1,
             'XC_POW' => -1,
             'XC_PRE_DEC_OBJ' => -1,
             'XC_PRE_INC_OBJ' => -1,
             'XC_QM_ASSIGN_VAR' => -1,
             'XC_RAISE_ABSTRACT_ERROR' => -1,
             'XC_RETURN_BY_REF' => -1,
             'XC_THROW' => -1,
             'XC_UNSET_DIM' => -1,
             'XC_UNSET_DIM_OBJ' => -1,
             'XC_UNSET_OBJ' => -1,
             'XC_USER_OPCODE' => -1,
             'XC_VERIFY_ABSTRACT_CLASS' => -1,
             'XC_YIELD' => -1,
         ) as $k => $v) {
    if (!defined($k)) {
        define($k, $v);
    }
}

// }}}
?>