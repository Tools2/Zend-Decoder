<?php

define('INDENT', "\t");
ini_set('error_reporting', E_ALL);
assert_options(ASSERT_ACTIVE, 0);
if (function_exists("gc_disable")) {
    gc_collect_cycles();
    gc_disable();
}

function color($str, $color = 33)
{
    return "\x1B[{$color}m$str\x1B[0m";
}

class Decompiler_Output
{
    // {{{
    var $errorToOutput = true;
    var $target;

    function Decompiler_Output($target)
    {
        $this->target = $target;
    }
    // }}}

    var $indent = "";
    function indent() // {{{
    {
        $this->indent .= INDENT;
    }
    // }}}
    function outdent() // {{{
    {
        $this->indent = substr($this->indent, 0, -strlen(INDENT));
    }
    // }}}

    function writeOne_($code) // {{{
    {
        echo $code;
    }
    // }}}
    var $writes = 0;
    function write() // {{{
    {
        ++$this->writes;
        foreach (func_get_args() as $code) {
            if (is_object($code)) {
                $s = $code;
                $code = $code->toCode($this->indent);
            }
            if (is_array($code)) {
                call_user_func_array(array(&$this, 'write'), $code);
            }
            else {
                $this->writeOne_((string) $code);
            }
        }
    }
    // }}}
    function writeln() // {{{
    {
        if ($this->codeTypeCurrent != $this->codeTypeNext) {
            if ($this->codeTypeCurrent && $this->codeTypeNext == 'line') {
                $this->writeOne_(PHP_EOL);
            }
            $this->codeTypeCurrent = $this->codeTypeNext;
        }
        $this->writeOne_($this->indent);

        $args = func_get_args();
        call_user_func_array(array(&$this, 'write'), $args);

        $this->writeOne_(PHP_EOL);
    }
    // }}}
    function printfError($format) // {{{
    {
        $args = func_get_args();
        unset($args[0]);

        foreach ($args as $i => $arg) {
            unset($arg);

            if (is_object($args[$i])) {
                $args[$i] = $args[$i]->toCode($this->indent);
            }
        }

        $error = implode("", $args);
        trigger_error($error);
        if ($this->errorToOutput) {
            $this->beginComment();
            fwrite($this->target, $error);
            $this->endComment();
        }
    }
    // }}}

    var $inComment = 0;
    function beginComment() // {{{
    {
        if (!$this->inComment++) {
            $this->writeln("/*");
        }
    }
    // }}}
    function endComment() // {{{
    {
        if (!--$this->inComment) {
            $this->writeln("*/");
        }
    }
    // }}}

    var $scopeStack = array();
    var $codeTypeCurrent = null;
    var $codeTypeNext = 'line';
    /*
	line  <- line

	something { <- complexblock
		line <- line
	} <- complexblock

	sdf <- line
	*/
    function beginBlock_($isComplex) // {{{
    {
        // array_push($this->scopeStack, array($this->codeTypeCurrent, $this->codeTypeNext));
        array_push($this->scopeStack, $this->codeTypeNext);
        $this->codeTypeCurrent = null;
        $this->codeTypeNext = $isComplex ? 'complexblock' : 'line';
    }
    // }}}
    function endBlock_() // {{{
    {
        // list($this->codeTypeCurrent, $this->codeTypeNext) = array_pop($this->scopeStack);
        $this->codeTypeNext = array_pop($this->scopeStack);
    }
    // }}}
    function beginScope() // {{{
    {
        $this->beginBlock_(false);
        $this->indent();
    }
    // }}}
    function endScope() // {{{
    {
        $this->outdent();
        $this->endBlock_();
    }
    // }}}
    function beginComplexBlock() // {{{
    {
        if (isset($this->codeTypeCurrent)) {
            $this->writeOne_(PHP_EOL);
        }

        $this->beginBlock_(true);
    }
    // }}}
    function endComplexBlock() // {{{
    {
        $this->endBlock_();
    }
    // }}}
}

class Decompiler_Backtrace // {{{
{
    function Decompiler_Backtrace()
    {
        $this->backtrace = debug_backtrace();
        array_shift($this->backtrace);
    }

    function toCode($indent)
    {
        $code = array();
        foreach ($this->backtrace as $stack) {
            $args = array();
            foreach ($stack['args'] as $arg) {
                if (is_scalar($arg)) {
                    $args[] = var_export($arg, true);
                }
                else if (is_array($arg)) {
                    $array = array();
                    foreach ($arg as $key => $value) {
                        $array[] = var_export($key, true) . " => " . (is_scalar($value) ? var_export($value, true) : gettype($value));
                        if (count($array) >= 5) {
                            $array[] = '...';
                            break;
                        }
                    }
                    $args[] = "array(" . implode(', ', $array) . ')';
                }
                else {
                    $args[] = gettype($arg);
                }
            }
            $code[] = sprintf("%s%d: %s::%s(%s)" . PHP_EOL
                , $indent
                , $stack['line']
                , isset($stack['class']) ? $stack['class'] : ''
                , $stack['function']
                , implode(', ', $args)
            );
        }
        return implode("", $code);
    }
}
// }}}
function printBacktrace() // {{{
{
    $decompiler = &$GLOBALS['__xcache_decompiler'];
    $decompiler->output->beginComment();
    $decompiler->output->write(new Decompiler_Backtrace());
    $decompiler->output->endComment();
}
// }}}

function decompileAst($ast) // {{{
{
    $kind = $ast['kind'];
    $children = $ast['children'];
    unset($ast['kind']);
    unset($ast['children']);
    $decompiler = &$GLOBALS['__xcache_decompiler'];

    switch ($kind) {
        case ZEND_CONST:
            return value($ast[0]);

        case XC_INIT_ARRAY:
            $array = new Decompiler_Array($decompiler);
            for ($i = 0; $i < $children; $i += 2) {
                if (isset($ast[$i + 1])) {
                    $key = decompileAst($ast[$i]);
                    $value = decompileAst($ast[$i + 1]);
                    $array->value[] = array($key, $value, '');
                }
                else {
                    $array->value[] = array(null, decompileAst($ast[$i]), '');
                }
            }
            return $array;

        // ZEND_BOOL_AND: handled in binop
        // ZEND_BOOL_OR:  handled in binop

        case ZEND_SELECT:
            return new Decompiler_TernaryOp($decompiler
                , decompileAst($ast[0])
                , decompileAst($ast[1])
                , decompileAst($ast[2])
            );

        case ZEND_UNARY_PLUS:
            return new Decompiler_UnaryOp($decompiler, XC_ADD, decompileAst($ast[0]));

        case ZEND_UNARY_MINUS:
            return new Decompiler_UnaryOp($decompiler, XC_SUB, decompileAst($ast[0]));

        default:
            if (isset($decompiler->binaryOp[$kind])) {
                return new Decompiler_BinaryOp($decompiler
                    , decompileAst($ast[0])
                    , $kind
                    , decompileAst($ast[1])
                );
            }

            return "un-handled kind $kind in zend_ast";
    }
}
// }}}
function value($value) // {{{
{
    if (ZEND_ENGINE_2_6 && (xcache_get_type($value) & IS_CONSTANT_TYPE_MASK) == IS_CONSTANT_AST) {
        return decompileAst(xcache_dasm_ast($value));
    }

    $decompiler = &$GLOBALS['__xcache_decompiler'];

    $originalValue = xcache_get_special_value($value);
    if (isset($originalValue)) {
        if ((xcache_get_type($value) & IS_CONSTANT_TYPE_MASK) == IS_CONSTANT) {
            // constant
            return new Decompiler_Code($decompiler, $decompiler->stripNamespace($originalValue));
        }

        $value = $originalValue;
    }

    if (is_a($value, 'Decompiler_Object')) {
        // use as is
    }
    else if (is_array($value)) {
        $value = new Decompiler_ConstArray($decompiler, $value);
    }
    else {
        if (is_scalar($value) && ($constant = $decompiler->value2constant($value)) && isset($constant)) {
            $value = new Decompiler_Code($decompiler, $constant);
        }
        else {
            $value = new Decompiler_Value($decompiler, $value);
        }
    }
    return $value;
}
// }}}
function unquoteName_($str, $asVariableName, $indent = '') // {{{
{
    $str = is_object($str) ? $str->toCode($indent) : $str;
    if (preg_match("!^'[\\w_][\\w\\d_\\\\]*'\$!", $str)) {
        return str_replace('\\\\', '\\', substr($str, 1, -1));
    }
    else if ($asVariableName) {
        return "{" . $str . "}";
    }
    else {
        return $str;
    }
}
// }}}
function unquoteVariableName($str, $indent = '') // {{{
{
    return unquoteName_($str, true, $indent);
}
// }}}
function unquoteName($str, $indent = '') // {{{
{
    return unquoteName_($str, false, $indent);
}
// }}}
class Decompiler_Object // {{{
{
    function toCode($indent)
    {
        return "";
    }
}
// }}}
class Decompiler_Value extends Decompiler_Object // {{{
{
    var $value;

    function Decompiler_Value(&$decompiler, $value = null)
    {
        $this->decompiler = &$decompiler;
        $this->value = $value;
    }

    function toCode($indent)
    {
        $code = var_export($this->value, true);
        if (gettype($this->value) == 'string') {
            $code = preg_replace_callback("![\t\r\n]+!", array(&$this, 'convertNewline'), $code);
            $code = preg_replace_callback("![\\x01-\\x1f]+!", array(&$this, 'escapeString'), $code);
            $code = preg_replace_callback("![\\x7f-\\xff]+!", array(&$this, 'escape8BitString'), $code);
            $code = preg_replace("!^'' \\. \"|\" \\. ''\$!", '"', $code);
        }
        return $code;
    }

    function convertNewline($m)
    {
        return "' . \"" . strtr($m[0], array("\t" => "\\t", "\r" => "\\r", "\n" => "\\n")) . "\" . '";
    }

    function escape8BitString($m)
    {
        if (function_exists("iconv") && @iconv("UTF-8", "UTF-8//IGNORE", $m[0]) == $m[0]) {
            return $m[0];
        }
        return $this->escapeString($m);
    }

    function escapeString($m)
    {
        $s = $m[0];
        $escaped = '';
        for ($i = 0, $c = strlen($s); $i < $c; ++$i) {
            $escaped .= "\\x" . dechex(ord($s[$i]));
        }
        return "' . \"" . $escaped . "\" . '";
    }
}
// }}}
class Decompiler_Code extends Decompiler_Object // {{{
{
    var $src;

    function Decompiler_Code(&$decompiler, $src)
    {
        $this->decompiler = &$decompiler;
        if (!assert('isset($src)')) {
            printBacktrace();
        }
        $this->src = $src;
    }

    function toCode($indent)
    {
        return $this->src;
    }
}
// }}}
class Decompiler_Statements extends Decompiler_Code // {{{
{
    var $src;

    function toCode($indent)
    {
        $code = array();
        foreach ($this->src as $i => $src) {
            if ($i) {
                $code[] = ', ';
            }
            $code[] = $src;
        }
        return $code;
    }
}
// }}}
class Decompiler_UnaryOp extends Decompiler_Code // {{{
{
    var $opc;
    var $op;

    function Decompiler_UnaryOp(&$decompiler, $opc, $op)
    {
        $this->decompiler = &$decompiler;
        $this->opc = $opc;
        $this->op = $op;
    }

    function toCode($indent)
    {
        $opstr = $this->decompiler->unaryOp[$this->opc];

        if (is_a($this->op, 'Decompiler_TernaryOp') || is_a($this->op, 'Decompiler_BinaryOp') && $this->op->opc != $this->opc) {
            $op = array("(", $this->op, ")");
        }
        else {
            $op = $this->op;
        }
        return array($opstr, $op);
    }
}
// }}}
class Decompiler_BinaryOp extends Decompiler_Code // {{{
{
    var $opc;
    var $op1;
    var $op2;

    function Decompiler_BinaryOp(&$decompiler, $op1, $opc, $op2)
    {
        $this->decompiler = &$decompiler;
        $this->opc = $opc;
        $this->op1 = $op1;
        $this->op2 = $op2;
    }

    function toCode($indent)
    {
        $opstr = $this->decompiler->binaryOp[$this->opc];

        if (is_a($this->op1, 'Decompiler_TernaryOp') || is_a($this->op1, 'Decompiler_BinaryOp') && $this->op1->opc != $this->opc) {
            $op1 = array("(", $this->op1, ")");
        }
        else {
            $op1 = $this->op1;
        }

        if (is_a($this->op2, 'Decompiler_TernaryOp') || is_a($this->op2, 'Decompiler_BinaryOp') && $this->op2->opc != $this->opc && substr($opstr, -1) != '=') {
            $op2 = array("(", $this->op2, ")");
        }
        else {
            $op2 = $this->op2;
        }

        if (is_a($op1, 'Decompiler_Value') && $op1->value == '0' && ($this->opc == XC_ADD || $this->opc == XC_SUB)) {
            $unaryOp = new Decompiler_UnaryOp($this->decompiler, $this->opc, $op2);
            return $unaryOp->toCode($indent);
        }

        return array($op1, ' ', $opstr, ($this->opc == XC_ASSIGN_REF ? '' : ' '), $op2);
    }
}
// }}}
class Decompiler_TernaryOp extends Decompiler_Code // {{{
{
    var $condition;
    var $trueValue;
    var $falseValue;

    function Decompiler_TernaryOp(&$decompiler, $condition, $trueValue, $falseValue)
    {
        $this->decompiler = &$decompiler;
        $this->condition = $condition;
        $this->trueValue = $trueValue;
        $this->falseValue = $falseValue;
    }

    function toCode($indent)
    {
        $trueValue = $this->trueValue;
        if (is_a($this->trueValue, 'Decompiler_TernaryOp')) {
            $trueValue = array("(", $trueValue, ")");
        }
        $falseValue = $this->falseValue;
        if (is_a($this->falseValue, 'Decompiler_TernaryOp')) {
            $falseValue = array("(", $falseValue, ")");
        }

        return array($this->condition, ' ? ', $trueValue, ' : ', $falseValue);
    }
}
// }}}
class Decompiler_Fetch extends Decompiler_Code // {{{
{
    var $fetchType;
    var $name;
    var $scope;

    function Decompiler_Fetch(&$decompiler, $scope, $name, $type)
    {
        $this->decompiler = &$decompiler;
        $this->scope = $scope;
        $this->name = $name;
        unset($this->src);
        $this->fetchType = $type;
    }

    function toCode($indent)
    {
        switch ($this->fetchType) {
            case XC_FETCH_PROPERTY:
                return array($this->scope, '->', $this->name);

            case ZEND_FETCH_STATIC_MEMBER:
                return array($this->scope, '::$', $this->name);

            case ZEND_FETCH_LOCAL:
                return array('$', $this->name);

            case ZEND_FETCH_STATIC:
                if (ZEND_ENGINE_2_3) {
                    // closure local variable?
                    return 'STR' . $this->name;
                }
                else {
                    return value($this->name);
                }
                die('static fetch cant to string');

            case ZEND_FETCH_GLOBAL:
            case ZEND_FETCH_GLOBAL_LOCK:
                return xcache_is_autoglobal($this->name) ? array('$', $this->name) : array("\$GLOBALS[", value($this->name), "]");

            default:
                var_dump($this->fetchType);
                assert(0);
        }
    }
}
// }}}
class Decompiler_Box extends Decompiler_Object // {{{
{
    var $obj;

    function Decompiler_Box(&$decompiler, &$obj)
    {
        $this->decompiler = &$decompiler;
        $this->obj = &$obj;
    }

    function toCode($indent)
    {
        return $this->obj->toCode($indent);
    }
}
// }}}
class Decompiler_Dim extends Decompiler_Value // {{{
{
    var $offsets = array();
    var $isLast = false;
    var $isObject = false;
    var $assign = null;

    function toCode($indent)
    {
        if (is_a($this->value, 'Decompiler_ListBox')) {
            $exp = array($this->value->obj->src);
        }
        else {
            $exp = array($this->value);
        }
        $last = count($this->offsets) - 1;
        foreach ($this->offsets as $i => $dim) {
            if ($this->isObject && $i == $last) {
                $exp[] = '->';
                $exp[] = unquoteVariableName($dim, $indent);
            }
            else {
                $exp[] = '[';
                $exp[] = $dim;
                $exp[] = ']';
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
    var $everLocked = false;

    function toCode($indent)
    {
        if (count($this->dims) == 1 && !$this->everLocked) {
            $dim = $this->dims[0];
            unset($dim->value);
            $dim->value = $this->src;
            if (!isset($dim->assign)) {
                return $dim;
            }
            return array($this->dims[0]->assign, ' = ', $dim);
        }
        /* flatten dims */
        $assigns = array();
        foreach ($this->dims as $dim) {
            $assign = &$assigns;
            foreach ($dim->offsets as $offset) {
                $assign = &$assign[$offset->value]; //fix
            }
            $assign = $dim->assign;
        }
        return array($this->toList($assigns), ' = ', $this->src);
    }

    function toList($assigns)
    {
        $keys = array_keys($assigns);
        if (count($keys) < 2) {
            $keys[] = 0;
        }
        $max = call_user_func_array('max', $keys);
        $code = array("list(");
        for ($i = 0; $i <= $max; $i++) {
            if ($i) {
                $code[] = ', ';
            }
            if (!isset($assigns[$i])) {
                continue;
            }
            if (is_array($assigns[$i])) {
                $code[] = $this->toList($assigns[$i]);
            }
            else {
                $code[] = $assigns[$i];
            }
        }
        $code[] = ')';
        return $code;
    }
}
// }}}
class Decompiler_ListBox extends Decompiler_Box // {{{
{
}
// }}}
class Decompiler_Array extends Decompiler_Value // {{{
{
    // elements
    function Decompiler_Array(&$decompiler)
    {
        $this->decompiler = &$decompiler;
        $this->value = array();
    }

    function toCode($indent)
    {
        $subindent = $indent . INDENT;

        $elementsCode = array();
        $index = 0;
        foreach ($this->value as $element) {
            $key = $element[0];
            if (isset($key)) {
                $key = value($key);
                $keyCode = $key->toCode($subindent);
            }
            else {
                $keyCode = $index++;
            }
            $element[0] = $keyCode;
            $elementsCode[] = $element;
        }

        $code = array("array(");
        $indent = $indent . INDENT;
        $assocWidth = 0;
        $multiline = 0;
        $i = 0;
        foreach ($elementsCode as $element) {
            $keyCode = $element[0];
            if (is_array($keyCode) || (string) $i !== (string) $keyCode) {
                $assocWidth = 1;
                break;
            }
            ++$i;
        }
        foreach ($elementsCode as $element) {
            list($keyCode, $value) = $element;
            if ($assocWidth && !is_array($keyCode)) {
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
            list($keyCode, $value, $ref) = $element;
            if ($multiline) {
                if ($i) {
                    $code[] = ",";
                }
                $code[] = PHP_EOL;
                $code[] = $indent;
            }
            else {
                if ($i) {
                    $code[] = ", ";
                }
            }

            if ($assocWidth) {
                if ($multiline && !is_array($keyCode)) {
                    $code[] = sprintf("%-{$assocWidth}s => ", $keyCode);
                }
                else {
                    $code[] = $keyCode;
                    $code[] = ' => ';
                }
            }

            $code[] = $ref;
            $value = value($value);
            $code[] = $value->toCode($subindent);

            $i++;
        }
        if ($multiline) {
            $code[] = PHP_EOL . "$indent)";
        }
        else {
            $code[] = ")";
        }
        return $code;
    }
}
// }}}
class Decompiler_ConstArray extends Decompiler_Array // {{{
{
    function Decompiler_ConstArray(&$decompiler, $array)
    {
        $this->decompiler = &$decompiler;
        $elements = array();
        foreach ($array as $key => $value) {
            if ((xcache_get_type($value) & IS_CONSTANT_INDEX)) {
                $keyCode = new Decompiler_Code($this, $GLOBALS['__xcache_decompiler']->stripNamespace(
                    ZEND_ENGINE_2_3
                        ? substr($key, 0, -2)
                        : $key
                ));
            }
            else {
                $keyCode = $key;
            }
            $elements[] = array($keyCode, value($value), '');
        }
        $this->value = $elements;
    }
}
// }}}
class Decompiler_ForeachBox extends Decompiler_Box // {{{
{
    var $iskey;

    function toCode($indent)
    {
        return '#foreachBox#';
    }
}
// }}}

class Decompiler
{
    var $namespace;
    var $namespaceDecided;
    var $activeFile;
    var $activeDir;
    var $activeClass;
    var $activeMethod;
    var $activeFunction;
    var $outputPhp;
    var $outputOpcode;
    var $value2constant = array();
    var $output;
    function Decompiler($outputTypes)
    {
        $this->outputPhp = in_array('php', $outputTypes);
        $this->outputOpcode = in_array('opcode', $outputTypes);
        $this->output = new Decompiler_Output("");
        $GLOBALS['__xcache_decompiler'] = $this;
        // {{{ testing
        // XC_UNDEF XC_OP_DATA
        $this->test = !empty($_ENV['XCACHE_DECOMPILER_TEST']);
        $this->usedOps = array();

        if ($this->test) {
            $content = file_get_contents(__FILE__);
            for ($i = 0; $opname = xcache_get_opcode($i); $i++) {
                if (!preg_match("/\\bXC_" . $opname . "\\b(?!')/", $content)) {
                    echo "not recognized opcode ", $opname, PHP_EOL;
                }
            }
        }
        // }}}
        // {{{ opinfo
        $this->unaryOp = array(
            XC_BW_NOT   => '~',
            XC_BOOL_NOT => '!',
            XC_ADD      => "+",
            XC_SUB      => "-",
            XC_NEW      => "new ",
            XC_THROW    => "throw ",
            XC_CLONE    => "clone ",
        );
        $this->binaryOp = array(
            XC_ADD                 => "+",
            XC_ASSIGN_ADD          => "+=",
            XC_SUB                 => "-",
            XC_ASSIGN_SUB          => "-=",
            XC_MUL                 => "*",
            XC_ASSIGN_MUL          => "*=",
            XC_DIV                 => "/",
            XC_ASSIGN_DIV          => "/=",
            XC_MOD                 => "%",
            XC_ASSIGN_MOD          => "%=",
            XC_SL                  => "<<",
            XC_ASSIGN_SL           => "<<=",
            XC_SR                  => ">>",
            XC_ASSIGN_SR           => ">>=",
            XC_CONCAT              => ".",
            XC_ASSIGN_CONCAT       => ".=",
            XC_POW                 => "**",
            XC_ASSIGN_POW          => "*=",
            XC_IS_IDENTICAL        => "===",
            XC_IS_NOT_IDENTICAL    => "!==",
            XC_IS_EQUAL            => "==",
            XC_IS_NOT_EQUAL        => "!=",
            XC_IS_SMALLER          => "<",
            XC_IS_SMALLER_OR_EQUAL => "<=",
            XC_BW_OR               => "|",
            XC_ASSIGN_BW_OR        => "|=",
            XC_BW_AND              => "&",
            XC_ASSIGN_BW_AND       => "&=",
            XC_BW_XOR              => "^",
            XC_ASSIGN_BW_XOR       => "^=",
            XC_BOOL_XOR            => "xor",
            XC_ASSIGN              => "=",
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
            $this->binaryOp[ZEND_BOOL_AND] = '&&';
            $this->binaryOp[ZEND_BOOL_OR]  = '||';
        }
        // }}}
        $this->includeTypes = array( // {{{
            ZEND_EVAL         => 'eval',
            ZEND_INCLUDE      => 'include',
            ZEND_INCLUDE_ONCE => 'include_once',
            ZEND_REQUIRE      => 'require',
            ZEND_REQUIRE_ONCE => 'require_once',
        );
        // }}}
    }
    function detectNamespace($name) // {{{
    {
        if ($this->namespaceDecided) {
            return;
        }

        if (strpos($name, '\\') !== false) {
            $namespace = strtok($name, '\\');
            if ($namespace == $this->namespace) {
                return;
            }

            $this->namespace = $namespace;
            $this->output->beginComplexBlock();
            $this->output->writeln('namespace ', $this->namespace, ";");
            $this->output->endComplexBlock();
        }

        $this->namespaceDecided = true;
    }
    // }}}
    function stripNamespace($name) // {{{
    {
        if (!isset($name)) {
            return $name;
        }

        $len = strlen($this->namespace) + 1;
        if (!is_string($name)) {
            printBacktrace();
            exit;
        }
        if (strncasecmp($name, $this->namespace . '\\', $len) == 0) {
            return substr($name, $len);
        }
        else {
            return $name;
        }
    }
    // }}}
    function value2constant($value) // {{{
    {
        if (isset($this->EX) && isset($this->EX['value2constant'][$value])) {
            return $this->EX['value2constant'][$value];
        }
        else if (isset($this->value2constant[$value])) {
            return $this->value2constant[$value];
        }
    }
    // }}}
    function outputPhp($range) // {{{
    {
        $curticks = 0;
        for ($i = $range[0]; $i <= $range[1]; $i++) {
            $op = $this->EX['opcodes'][$i];
            if (isset($op['gofrom'])) {
                $this->output->beginComplexBlock();
                echo 'label' . $i, ":", PHP_EOL;
                $this->output->endComplexBlock();
            }
            if (isset($op['php'])) {
                $toticks = isset($op['ticks']) ? (int) $op['ticks'] : 0;
                if ($curticks != $toticks) {
                    if ($curticks) {
                        $this->output->endScope();
                        $this->output->writeln("}");
                        $this->output->endComplexBlock();
                    }
                    $curticks = $toticks;
                    if ($curticks) {
                        $this->output->beginComplexBlock();
                        $this->output->writeln("declare (ticks=$curticks) {");
                        $this->output->beginScope();
                    }
                }
                $this->output->writeln($op['php'], ';');
                unset($op['php']);
            }
        }
        if ($curticks) {
            $this->output->endScope();
            $this->output->writeln("}");
            $this->output->endComplexBlock();
        }
    }
    // }}}
    function getOpVal($op, $free = false, $namespaced = false) // {{{
    {
        switch ($op['op_type']) {
            case XC_IS_CONST:
                if ($namespaced && isset($op['constant'])) {
                    return $this->stripNamespace($op['constant']);
                }
                return value($op['constant']);

            case XC_IS_VAR:
            case XC_IS_TMP_VAR:
                $T = &$this->EX['Ts'];
                if (!isset($T[$op['var']])) {
                    if ($this->outputPhp && isset($free)) {
                        printBacktrace();
                    }
                    return null;
                }
                $ret = $T[$op['var']];
                if ($free && empty($this->keepTs)) {
                    unset($T[$op['var']]);
                }
                return $ret;

            case XC_IS_CV:
                $vars = &$this->EX['op_array']['vars'];
                $var = $op['var'];
                return new Decompiler_Fetch($this, null, isset($vars[$var]) ? $vars[$var]['name'] : '?', ZEND_FETCH_LOCAL);

            case XC_IS_UNUSED:
                return null;
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
    function fixOpCode(&$opcodes, $removeTailing = false, $defaultReturnValue = null) // {{{
    {
        $last = count($opcodes) - 1;
        for ($i = 0; $i <= $last; $i++) {
            if (function_exists('xcache_get_fixed_opcode')) {
                $opcodes[$i]['opcode'] = xcache_get_fixed_opcode($opcodes[$i]['opcode'], $i);
            }
            if (isset($opcodes[$i]['op1'])) {
                $opcodes[$i]['op1'] = $this->removeKeyPrefix($opcodes[$i]['op1'], 'u.');
                $opcodes[$i]['op2'] = $this->removeKeyPrefix($opcodes[$i]['op2'], 'u.');
                $opcodes[$i]['result'] = $this->removeKeyPrefix($opcodes[$i]['result'], 'u.');
            }
            else {
                $op = array(
                    'op1' => array(),
                    'op2' => array(),
                    'result' => array(),
                );
                foreach ($opcodes[$i] as $name => $value) {
                    if (preg_match('!^(op1|op2|result)\\.(.*)!', $name, $m)) {
                        list(, $which, $field) = $m;
                        $op[$which][$field] = $value;
                    }
                    else if (preg_match('!^(op1|op2|result)_type$!', $name, $m)) {
                        list(, $which) = $m;
                        $op[$which]['op_type'] = $value;
                    }
                    else {
                        $op[$name] = $value;
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
    }
    // }}}
    function decompileBasicBlock($range, $unhandled = false) // {{{
    {
        $this->dasmBasicBlock($range);
        if ($unhandled) {
            $this->dumpRange($range);
        }
        $this->outputPhp($range);
    }
    // }}}
    function isIfCondition($range) // {{{
    {
        $opcodes = &$this->EX['opcodes'];
        $firstOp = &$opcodes[$range[0]];
        return $firstOp['opcode'] == XC_JMPZ && !empty($firstOp['jmptos']) && $opcodes[$firstOp['jmptos'][0] - 1]['opcode'] == XC_JMP
            && !empty($opcodes[$firstOp['jmptos'][0] - 1]['jmptos'])
            && $opcodes[$firstOp['jmptos'][0] - 1]['jmptos'][0] == $range[1] + 1;
    }
    // }}}
    function removeJmpInfo($line) // {{{
    {
        $opcodes = &$this->EX['opcodes'];
        if (!isset($opcodes[$line]['jmptos'])) {
            printBacktrace();
        }
        foreach ($opcodes[$line]['jmptos'] as $jmpTo) {
            $jmpfroms = &$opcodes[$jmpTo]['jmpfroms'];
            $jmpfroms = array_flip($jmpfroms);
            unset($jmpfroms[$line]);
            $jmpfroms = array_keys($jmpfroms);
        }
        // $opcodes[$line]['opcode'] = XC_NOP;
        unset($opcodes[$line]['jmptos']);
    }
    // }}}
    function op($range, $offset) // {{{
    {
        $opcodes = &$this->EX['opcodes'];
        if ($offset > 0) {
            for ($i = $offset; $i <= $range[1]; ++$i) {
                if ($opcodes[$i]['opcode'] != XC_NOP) {
                    return $i;
                }
            }
        }
        else {
            for ($i = -$offset; $i >= $range[0]; --$i) {
                if ($opcodes[$i]['opcode'] != XC_NOP) {
                    return $i;
                }
            }
        }
        return -1;
    }
    // }}}
    function decompileComplexBlock($range) // {{{
    {
        $opcodes = &$this->EX['opcodes'];

        $firstOp = &$opcodes[$this->op($range, $range[0])];
        $lastOp = &$opcodes[$this->op($range, -$range[1])];

        // {{{ && || and or
        if (($firstOp['opcode'] == XC_JMPZ_EX || $firstOp['opcode'] == XC_JMPNZ_EX) && !empty($firstOp['jmptos'])
            && $firstOp['jmptos'][0] == $range[1] + 1
            && $lastOp['opcode'] == XC_BOOL
            && $firstOp['opcode']['result']['var'] == $lastOp['opcode']['result']['var']
        ) {
            $this->removeJmpInfo($range[0]);

            $this->recognizeAndDecompileClosedBlocks(array($range[0], $range[0]));
            $op1 = $this->getOpVal($firstOp['result'], true);

            $this->recognizeAndDecompileClosedBlocks(array($range[0] + 1, $range[1]));
            $op2 = $this->getOpVal($lastOp['result'], true);

            $this->EX['Ts'][$firstOp['result']['var']] = new Decompiler_BinaryOp($this, $op1, $firstOp['opcode'], $op2);
            return false;
        }
        // }}}
        // {{{ ?: excluding JMP_SET/JMP_SET_VAR
        if ($firstOp['opcode'] == XC_JMPZ && !empty($firstOp['jmptos'])
            && $range[1] >= $range[0] + 3
            && ($opcodes[$firstOp['jmptos'][0] - 2]['opcode'] == XC_QM_ASSIGN || $opcodes[$firstOp['jmptos'][0] - 2]['opcode'] == XC_QM_ASSIGN_VAR)
            && $opcodes[$firstOp['jmptos'][0] - 1]['opcode'] == XC_JMP && $opcodes[$firstOp['jmptos'][0] - 1]['jmptos'][0] == $range[1] + 1
            && ($lastOp['opcode'] == XC_QM_ASSIGN || $lastOp['opcode'] == XC_QM_ASSIGN_VAR)
        ) {
            $trueRange = array($range[0] + 1, $firstOp['jmptos'][0] - 2);
            $falseRange = array($firstOp['jmptos'][0], $range[1]);
            $this->removeJmpInfo($range[0]);

            $condition = $this->getOpVal($firstOp['op1']);
            $this->recognizeAndDecompileClosedBlocks($trueRange);
            $trueValue = $this->getOpVal($opcodes[$trueRange[1]]['result'], true);
            $this->recognizeAndDecompileClosedBlocks($falseRange);
            $falseValue = $this->getOpVal($opcodes[$falseRange[1]]['result'], true);
            $this->EX['Ts'][$opcodes[$trueRange[1]]['result']['var']] = new Decompiler_TernaryOp($this, $condition, $trueValue, $falseValue);
            return false;
        }
        // }}}
        // {{{ goto (TODO: recognize BRK which is translated to JMP by optimizer)
        if ($firstOp['opcode'] == XC_JMP && !empty($firstOp['jmptos']) && $firstOp['jmptos'][0] == $range[1] + 1) {
            $this->removeJmpInfo($range[0]);
            assert(XC_GOTO != -1);
            $firstOp['opcode'] = XC_GOTO;
            $target = $firstOp['op1']['var'];
            $firstOp['goto'] = $target;
            $opcodes[$target]['gofrom'][] = $range[0];

            $this->recognizeAndDecompileClosedBlocks($range);
            return false;
        }
        // }}}

        // {{{ search firstJmpOp
        $firstJmpOp = null;
        for ($i = $range[0]; $i <= $range[1]; ++$i) {
            if (!empty($opcodes[$i]['jmptos'])) {
                $firstJmpOp = &$opcodes[$i];
                break;
            }
        }
        // }}}
        if (!isset($firstJmpOp)) {
            return;
        }
        // {{{ search lastJmpOp
        $lastJmpOp = null;
        for ($i = $range[1]; $i > $firstJmpOp['line']; --$i) {
            if (!empty($opcodes[$i]['jmptos'])) {
                $lastJmpOp = &$opcodes[$i];
                break;
            }
        }
        // }}}
        if ($this->decompile_foreach($range, $opcodes, $firstOp, $lastOp, $firstJmpOp, $lastJmpOp)) {
            return true;
        }
        if ($this->decompile_while($range, $opcodes, $firstOp, $lastOp, $firstJmpOp)) {
            return true;
        }
        if ($this->decompile_for($range, $opcodes, $firstOp, $lastOp)) {
            return true;
        }
        if ($this->decompile_if($range, $opcodes, $firstOp, $lastOp)) {
            return true;
        }
        if ($this->decompile_switch($range, $opcodes, $firstOp, $lastOp)) {
            return true;
        }
        if ($this->decompile_tryCatch($range, $opcodes, $firstOp, $lastOp)) {
            return true;
        }
        if ($this->decompile_doWhile($range, $opcodes, $firstOp, $lastOp)) {
            return true;
        }

        $this->decompileBasicBlock($range, true);
    }
    // }}}
    function decompile_for($range, &$opcodes, &$firstOp, &$lastOp) // {{{
    {
        if (!empty($firstOp['jmpfroms']) && $opcodes[$firstOp['jmpfroms'][0]]['opcode'] == XC_JMP
            && $lastOp['opcode'] == XC_JMP && !empty($lastOp['jmptos']) && $lastOp['jmptos'][0] <= $firstOp['jmpfroms'][0]
            && !empty($opcodes[$range[1] + 1]['jmpfroms']) && $opcodes[$opcodes[$range[1] + 1]['jmpfroms'][0]]['opcode'] == XC_JMPZNZ
        ) {
            $nextRange = array($lastOp['jmptos'][0], $firstOp['jmpfroms'][0]);
            $conditionRange = array($range[0], $nextRange[0] - 1);
            $this->removeJmpInfo($conditionRange[1]);
            $bodyRange = array($nextRange[1], $range[1]);
            $this->removeJmpInfo($bodyRange[1]);

            $this->output->beginComplexBlock();
            $initial = '';
            $this->output->beginScope();
            $this->dasmBasicBlock($conditionRange);
            $conditionCodes = array();
            for ($i = $conditionRange[0]; $i <= $conditionRange[1]; ++$i) {
                if (isset($opcodes[$i]['php'])) {
                    $conditionCodes[] = $opcodes[$i]['php'];
                }
            }
            $conditionCodes[] = $this->getOpVal($opcodes[$conditionRange[1]]['op1']);
            if (count($conditionCodes) == 1 && $conditionCodes[0] == 'true') {
                $conditionCodes = array();
            }
            $this->output->endScope();

            $this->output->beginScope();
            $this->dasmBasicBlock($nextRange);
            $nextCodes = array();
            for ($i = $nextRange[0]; $i <= $nextRange[1]; ++$i) {
                if (isset($opcodes[$i]['php'])) {
                    $nextCodes[] = $opcodes[$i]['php'];
                }
            }
            $this->output->endScope();

            $this->output->writeln('for (', $initial, '; ', new Decompiler_Statements($this, $conditionCodes), '; ', new Decompiler_Statements($this, $nextCodes), ') ', '{');
            $this->clearJmpInfo_brk_cont($bodyRange);
            $this->output->beginScope();
            $this->recognizeAndDecompileClosedBlocks($bodyRange);
            $this->output->endScope();
            $this->output->writeln('}');
            $this->output->endComplexBlock();
            return true;
        }
    }
    // }}}
    function decompile_if($range, &$opcodes, &$firstOp, &$lastOp) // {{{
    {
        if ($this->isIfCondition($range)) {
            $this->output->beginComplexBlock();
            $isElseIf = false;
            do {
                $ifRange = array($range[0], $opcodes[$range[0]]['jmptos'][0] - 1);
                $this->removeJmpInfo($ifRange[0]);
                $this->removeJmpInfo($ifRange[1]);
                $condition = $this->getOpVal($opcodes[$ifRange[0]]['op1']);

                $this->output->writeln($isElseIf ? 'else if' : 'if', ' (', $condition, ') ', '{');
                $this->output->beginScope();
                $this->recognizeAndDecompileClosedBlocks($ifRange);
                $this->output->endScope();
                $this->output->writeln("}");

                $isElseIf = true;
                // search for else if
                $range[0] = $ifRange[1] + 1;
                for ($i = $ifRange[1] + 1; $i <= $range[1]; ++$i) {
                    // find first jmpout
                    if (!empty($opcodes[$i]['jmptos'])) {
                        if ($this->isIfCondition(array($i, $range[1]))) {
                            $this->dasmBasicBlock(array($range[0], $i));
                            $range[0] = $i;
                        }
                        break;
                    }
                }
            } while ($this->isIfCondition($range));
            if ($ifRange[1] < $range[1]) { //fix 多余else
                $elseRange = array($ifRange[1], $range[1]);
                $this->output->writeln('else ', '{');
                $this->output->beginScope();
                $this->recognizeAndDecompileClosedBlocks($elseRange);
                $this->output->endScope();
                $this->output->writeln("}");
            }
            $this->output->endComplexBlock();
            return true;
        }

        if ($firstOp['opcode'] == XC_JMPZ && !empty($firstOp['jmptos'])
            && $firstOp['jmptos'][0] - 1 == $range[1]
            && ($opcodes[$firstOp['jmptos'][0] - 1]['opcode'] == XC_RETURN || $opcodes[$firstOp['jmptos'][0] - 1]['opcode'] == XC_GENERATOR_RETURN)) {
            $this->output->beginComplexBlock();
            $this->removeJmpInfo($range[0]);
            $condition = $this->getOpVal($opcodes[$range[0]]['op1']);

            $this->output->writeln('if (', $condition, ') ', '{');
            $this->output->beginScope();
            $this->recognizeAndDecompileClosedBlocks($range);
            $this->output->endScope();
            $this->output->writeln('}');
            $this->output->endComplexBlock();
            return true;
        }
    }
    // }}}
    function decompile_tryCatch($range, &$opcodes, &$firstOp, &$lastOp) // {{{
    {
        if (!empty($firstOp['jmpfroms']) && !empty($opcodes[$firstOp['jmpfroms'][0]]['isCatchBegin'])) {
            $catchBlocks = array();
            $catchFirst = $firstOp['jmpfroms'][0];

            $tryRange = array($range[0], $catchFirst - 1);

            // search for XC_CATCH
            for ($i = $catchFirst; $i <= $range[1]; ) {
                if ($opcodes[$i]['opcode'] == XC_CATCH) {
                    $catchOpLine = $i;
                    $this->removeJmpInfo($catchFirst);

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

            $this->output->beginComplexBlock();
            $this->output->writeln("try {");
            $this->output->beginScope();
            $this->recognizeAndDecompileClosedBlocks($tryRange);
            $this->output->endScope();
            $this->output->writeln("}");
            if (!$catchBlocks) {
                printBacktrace();
                assert($catchBlocks);
            }
            foreach ($catchBlocks as $catchFirst => $catchInfo) {
                list($catchOpLine, $catchBodyLast) = $catchInfo;
                $catchBodyFirst = $catchOpLine + 1;
                $this->dasmBasicBlock(array($catchFirst, $catchOpLine));
                $catchOp = &$opcodes[$catchOpLine];
                $this->output->writeln("catch ("
                    , $this->stripNamespace(isset($catchOp['op1']['constant']) ? $catchOp['op1']['constant'] : $this->getOpVal($catchOp['op1']))
                    , ' '
                    , isset($catchOp['op2']['constant']) ? '$' . $catchOp['op2']['constant'] : $this->getOpVal($catchOp['op2'])
                    , ") {"
                );
                unset($catchOp);

                $this->output->beginScope();
                $this->recognizeAndDecompileClosedBlocks(array($catchBodyFirst, $catchBodyLast));
                $this->output->endScope();
                $this->output->writeln("}");
            }
            $this->output->endComplexBlock();
            return true;
        }
    }
    // }}}
    function decompile_switch($range, &$opcodes, &$firstOp, &$lastOp) // {{{
    {
        if ($firstOp['opcode'] == XC_CASE && !empty($lastOp['jmptos'])
            || $firstOp['opcode'] == XC_JMP && !empty($firstOp['jmptos']) && $opcodes[$firstOp['jmptos'][0]]['opcode'] == XC_CASE && !empty($lastOp['jmptos'])
        ) {
            $this->clearJmpInfo_brk_cont($range);
            $cases = array();
            $caseDefault = null;
            $caseOp = null;
            for ($i = $range[0]; $i <= $range[1]; ) {
                $op = $opcodes[$i];
                if ($op['opcode'] == XC_CASE) {
                    if (!isset($caseOp)) {
                        $caseOp = $op;
                    }
                    $jmpz = $opcodes[$i + 1];
                    assert('$jmpz["opcode"] == XC_JMPZ');
                    $caseNext = $jmpz['jmptos'][0];
                    $cases[$i] = $caseNext - 1;
                    $i = $caseNext;
                }
                else if ($op['opcode'] == XC_JMP && $op['jmptos'][0] >= $i) {
                    // default
                    $caseNext = $op['jmptos'][0];
                    $caseDefault = $i;
                    $cases[$i] = $caseNext - 1;
                    $i = $caseNext;
                }
                else {
                    ++$i;
                }
            }

            $this->output->beginComplexBlock();

            $this->output->writeln('switch (', $this->getOpVal($caseOp['op1'], true), ") {");
            $caseIsOut = false;
            $caseExpressionBegin = $range[0];
            foreach ($cases as $caseFirst => $caseLast) {
                if ($caseExpressionBegin < $caseFirst) {
                    $this->recognizeAndDecompileClosedBlocks(array($caseExpressionBegin, $caseFirst - 1));
                }
                $caseExpressionBegin = $caseLast + 1;

                if ($caseIsOut && empty($lastCaseFall)) {
                    echo PHP_EOL;
                }

                $caseOp = $opcodes[$caseFirst];

                if ($caseOp['opcode'] == XC_CASE) {
                    $this->output->writeln('case ', $this->getOpVal($caseOp['op2']), ':');

                    $this->removeJmpInfo($caseFirst);
                    ++$caseFirst;

                    assert('$opcodes[$caseFirst]["opcode"] == XC_JMPZ');
                    $this->removeJmpInfo($caseFirst);
                    ++$caseFirst;
                }
                else {
                    $this->output->writeln('default:');

                    assert('$opcodes[$caseFirst]["opcode"] == XC_JMP');
                    $this->removeJmpInfo($caseFirst);
                    ++$caseFirst;
                }

                assert('$opcodes[$caseLast]["opcode"] == XC_JMP');
                $this->removeJmpInfo($caseLast);
                --$caseLast;
                switch ($opcodes[$caseLast]['opcode']) {
                    case XC_BRK:
                    case XC_CONT:
                    case XC_GOTO:
                        $lastCaseFall = false;
                        break;

                    default:
                        $lastCaseFall = true;
                }

                $this->output->beginScope();
                $this->recognizeAndDecompileClosedBlocks(array($caseFirst, $caseLast));
                $this->output->endScope();
                $caseIsOut = true;
            }
            $this->output->writeln('}');

            $this->output->endComplexBlock();
            return true;
        }
    }
    // }}}
    function decompile_doWhile($range, &$opcodes, &$firstOp, &$lastOp) // {{{
    {
        if ($lastOp['opcode'] == XC_JMPNZ && !empty($lastOp['jmptos'])
            && $lastOp['jmptos'][0] == $range[0]) {
            $this->removeJmpInfo($range[1]);
            $this->clearJmpInfo_brk_cont($range);
            $this->output->beginComplexBlock();

            $this->output->writeln("do {");
            $this->output->beginScope();
            $this->recognizeAndDecompileClosedBlocks($range);
            $this->output->endScope();
            $this->output->writeln("} while (", $this->getOpVal($lastOp['op1']), ');');

            $this->output->endComplexBlock();
            return true;
        }
    }
    // }}}
    function decompile_while($range, &$opcodes, &$firstOp, &$lastOp, &$firstJmpOp) // {{{
    {
        if ($firstJmpOp['opcode'] == XC_JMPZ
            && $firstJmpOp['jmptos'][0] > $range[1]
            && $lastOp['opcode'] == XC_JMP
            && !empty($lastOp['jmptos']) && $lastOp['jmptos'][0] == $range[0]) {
            $this->removeJmpInfo($firstJmpOp['line']);
            $this->removeJmpInfo($range[1]);
            $this->output->beginComplexBlock();

            ob_start();
            $this->output->beginScope();
            $this->recognizeAndDecompileClosedBlocks($range);
            $this->output->endScope();
            $body = ob_get_clean();

            $this->output->writeln("while (", $this->getOpVal($firstJmpOp['op1']), ") {");
            echo $body;
            $this->output->writeln('}');

            $this->output->endComplexBlock();
            return true;
        }
    }
    // }}}
    function decompile_foreach($range, &$opcodes, &$firstOp, &$lastOp, &$firstJmpOp, &$lastJmpOp) // {{{
    {
        if ($firstJmpOp['opcode'] == XC_FE_FETCH
            && !empty($firstJmpOp['jmptos']) && $firstJmpOp['jmptos'][0] > $lastJmpOp['line']
            && isset($lastJmpOp)
            && $lastJmpOp['opcode'] == XC_JMP
            && !empty($lastJmpOp['jmptos']) && $lastJmpOp['jmptos'][0] == $firstJmpOp['line']) {
            $this->removeJmpInfo($firstJmpOp['line']);
            $this->removeJmpInfo($lastJmpOp['line']);
            $this->clearJmpInfo_brk_cont($range);
            $this->output->beginComplexBlock();

            ob_start();
            $this->output->beginScope();
            $this->recognizeAndDecompileClosedBlocks($range);
            $this->output->endScope();
            $body = ob_get_clean();

            $as = $firstJmpOp['fe_as'];
            if (isset($firstJmpOp['fe_key'])) {
                $as = array($firstJmpOp['fe_key'], ' => ', $as);
            }

            $this->output->writeln("foreach (", $firstJmpOp['fe_src'], " as ", $as, ") {");
            echo $body;
            $this->output->writeln('}');

            $this->output->endComplexBlock();
            return true;
        }
    }
    // }}}
    function recognizeAndDecompileClosedBlocks($range) // {{{ decompile in a tree way
    {
        $opcodes = &$this->EX['opcodes'];
        if (count($opcodes) > 1000) {
            $total = 70;

            static $spinChars = array(
                '-', '\\', '|', '/'
            );
            if (!isset($this->EX['bar'])) {
                $this->EX['bar'] = str_repeat(' ', $total);
                $this->EX['barX'] = 0;
            }

            $left = $total;
            $bar = '';

            $width = floor($total * $range[0] / count($opcodes));
            $left -= $width;
            $bar .= str_repeat('>', $width);

            $width = ceil($total * ($range[1] - $range[0]) / count($opcodes));
            if ($left && !$width) {
                $width = 1;
            }
            $left -= $width;
            $bar .= str_repeat($spinChars[$this->EX['barX']++ % count($spinChars)], $width);

            $bar .= substr($this->EX['bar'], strlen($bar));
            $this->EX['bar'] = $bar;

            fwrite(STDERR, "\r[$bar]");
        }

        $ranges = array();
        $starti = $range[0];
        for ($i = $starti; $i <= $range[1]; ) {
            if (!empty($opcodes[$i]['jmpfroms']) || !empty($opcodes[$i]['jmptos'])) {
                $blockFirst = $i;
                $blockLast = -1;
                $j = $blockFirst;
                do {
                    $op = $opcodes[$j];
                    if (!empty($op['jmpfroms'])) {
                        // care about jumping from blocks behind, not before
                        foreach ($op['jmpfroms'] as $oplineNumber) {
                            if ($oplineNumber <= $range[1] && $blockLast < $oplineNumber) {
                                $blockLast = $oplineNumber;
                            }
                        }
                    }
                    if (!empty($op['jmptos'])) {
                        $blockLast = max($blockLast, max($op['jmptos']) - 1);
                    }
                    ++$j;
                } while ($j <= $blockLast);

                if ($blockLast > $range[1]) {
                    fprintf(STDERR, "%d: \$blockLast(%d) > \$range[1](%d)\n", __LINE__, $blockLast, $range[1]);
                    assert('$blockLast <= $range[1]');
                    printBacktrace();
                    $this->dumpRange($range);
                }

                if ($blockLast >= $blockFirst) {
                    if ($blockFirst > $starti) {
                        $this->decompileBasicBlock(array($starti, $blockFirst - 1));
                    }
                    $this->decompileComplexBlock(array($blockFirst, $blockLast));
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
            $this->decompileBasicBlock(array($starti, $range[1]));
        }
    }
    // }}}
    function buildJmpInfo($range) // {{{ build jmpfroms/jmptos to op_array
    {
        $op_array = &$this->EX['op_array'];
        $opcodes = &$this->EX['opcodes'];
        for ($i = $range[0]; $i <= $range[1]; $i++) {
            $op = &$opcodes[$i];
            switch ($op['opcode']) {
                case XC_CONT:
                case XC_BRK:
                    $jmpTo = null;
                    if ($op['op2']['op_type'] == XC_IS_CONST && is_int($op['op2']['constant'])) {
                        $nestedLevel = $op['op2']['constant'];
                        $arrayOffset = $op['op1']['opline_num'];
                        // zend_brk_cont
                        while ($nestedLevel-- > 0) {
                            if ($arrayOffset == -1) {
                                $jmpTo = null;
                                break;
                            }
                            if (!isset($op_array['brk_cont_array'][$arrayOffset])) {
                                fprintf(STDERR, "%d: brk/cont not found at #$i\n", __LINE__);
                                break;
                            }
                            $jmpTo = $op_array['brk_cont_array'][$arrayOffset];
                            $arrayOffset = $jmpTo['parent'];
                        }
                    }

                    $op['jmptos'] = array();
                    if (isset($jmpTo)) {
                        $jmpTo = $jmpTo[$op['opcode'] == XC_CONT ? 'cont' : 'brk'];
                        $op['jmptos'][] = $jmpTo;
                        $opcodes[$jmpTo]['jmpfroms'][] = $i;
                    }
                    break;

                case XC_GOTO:
                    $target = $op['op1']['var'];
                    if (!isset($opcodes[$target])) {
                        fprintf(STDERR, "%d: missing jump target at #$i" . PHP_EOL, __LINE__);
                        break;
                    }
                    $op['goto'] = $target;
                    $opcodes[$target]['gofrom'][] = $i;
                    break;

                case XC_JMP:
                    $target = $op['op1']['var'];
                    if (!isset($opcodes[$target])) {
                        fprintf(STDERR, "%d: missing jump target at #$i" . PHP_EOL, __LINE__);
                        break;
                    }
                    $op['jmptos'] = array($target);
                    $opcodes[$target]['jmpfroms'][] = $i;
                    break;

                case XC_JMPZNZ:
                    $jmpz = $op['op2']['opline_num'];
                    $jmpnz = $op['extended_value'];
                    if (!isset($opcodes[$jmpz])) {
                        fprintf(STDERR, "%d: missing jump target at #$i" . PHP_EOL, __LINE__);
                        break;
                    }
                    if (!isset($opcodes[$jmpnz])) {
                        fprintf(STDERR, "%d: missing jump target at #$i" . PHP_EOL, __LINE__);
                        break;
                    }
                    $op['jmptos'] = array($jmpz, $jmpnz);
                    $opcodes[$jmpz]['jmpfroms'][] = $i;
                    $opcodes[$jmpnz]['jmpfroms'][] = $i;
                    break;

                case XC_JMPZ:
                case XC_JMPNZ:
                case XC_JMPZ_EX:
                case XC_JMPNZ_EX:
                    // case XC_JMP_SET:
                    // case XC_JMP_SET_VAR:
                    // case XC_FE_RESET:
                case XC_FE_FETCH:
                    // case XC_JMP_NO_CTOR:
                    $target = $op['op2']['opline_num'];
                    if (!isset($opcodes[$target])) {
                        fprintf(STDERR, "%d: missing jump target at #$i" . PHP_EOL, __LINE__);
                        break;
                    }
                    $op['jmptos'] = array($target);
                    $opcodes[$target]['jmpfroms'][] = $i;
                    break;

                /*
			case XC_RETURN:
				$op['jmptos'] = array();
				break;
			*/

                case XC_CASE:
                    // just to link together
                    $op['jmptos'] = array($i + 2);
                    $opcodes[$i + 2]['jmpfroms'][] = $i;
                    break;

                case XC_CATCH:
                    $catchNext = $op['extended_value'];
                    $catchBegin = $opcodes[$i - 1]['opcode'] == XC_FETCH_CLASS ? $i - 1 : $i;
                    $opcodes[$catchBegin]['jmptos'] = array($catchNext);
                    $opcodes[$catchNext]['jmpfroms'][] = $catchBegin;
                    break;
            }
            /*
			if (!empty($op['jmptos']) || !empty($op['jmpfroms'])) {
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
                    $opcodes[$try_op]['jmpfroms'][] = $catch_op;
                    $opcodes[$catch_op]['jmptos'][] = $try_op;
                    if ($opcodes[$catch_op]['opcode'] == XC_CATCH) {
                        $catch_op = $opcodes[$catch_op]['extended_value'];
                    }
                    else if ($catch_op + 1 <= $range[1] && $opcodes[$catch_op + 1]['opcode'] == XC_CATCH) {
                        $catch_op = $opcodes[$catch_op + 1]['extended_value'];
                    }
                    else {
                        break;
                    }
                } while ($catch_op <= $range[1] && empty($opcodes[$catch_op]['isCatchBegin']));
            }
        }
    }
    // }}}
    function clearJmpInfo_brk_cont($range) // {{{ clear jmpfroms/jmptos for BRK/CONT relative to this range only
    {
        $opcodes = &$this->EX['opcodes'];
        for ($i = $range[0]; $i <= $range[1]; $i++) {
            $op = &$opcodes[$i];
            switch ($op['opcode']) {
                case XC_CONT:
                case XC_BRK:
                    if (!empty($op['jmptos'])) {
                        if ($op['jmptos'][0] == $range[0]
                            || $op['jmptos'][0] == $range[1] + 1) {
                            $this->removeJmpInfo($i);
                        }
                    }
                    break;
            }
        }
        unset($op);
    }
    // }}}
    function &dop_array($op_array, $isFunction = false) // {{{
    {
        $this->fixOpCode($op_array['opcodes'], true, $isFunction ? null : 1);

        $opcodes = &$op_array['opcodes'];

        $EX = array();
        $this->EX = &$EX;
        $EX['Ts'] = $this->outputPhp ? array() : null;
        $EX['op_array'] = &$op_array;
        $EX['opcodes'] = &$opcodes;
        // func call
        $EX['object'] = null;
        $EX['called_scope'] = null;
        $EX['fbc'] = null;
        $EX['argstack'] = array();
        $EX['arg_types_stack'] = array();
        $EX['silence'] = 0;
        $EX['recvs'] = array();
        $EX['uses'] = array();
        $EX['value2constant'] = array();
        if (isset($this->activeMethod)) {
            $EX['value2constant'][$this->activeMethod] = '__METHOD__';
        }
        if (isset($this->activeFunction)) {
            $EX['value2constant'][$this->activeFunction] = '__FUNCTION__';
        }

        $range = array(0, count($opcodes) - 1);
        for ($i = $range[0]; $i <= $range[1]; $i++) {
            $opcodes[$i]['line'] = $i;
        }
        $this->buildJmpInfo($range);

        if ($this->outputOpcode) {
            $this->keepTs = true;
            $this->dumpRange($range);
            $this->keepTs = false;
        }
        if ($this->outputPhp) {
            // decompile in a tree way
            $this->recognizeAndDecompileClosedBlocks($range);
        }
        unset($this->EX);
        return $EX;
    }
    // }}}
    function dasmBasicBlock($range) // {{{
    {
        $T = &$this->EX['Ts'];
        $opcodes = &$this->EX['opcodes'];
        $lastphpop = null;

        for ($i = $range[0]; $i <= $range[1]; $i++) {
            // {{{ prepair
            $op = &$opcodes[$i];
            $opc = $op['opcode'];
            if ($opc == XC_NOP) {
                $this->usedOps[$opc] = true;
                continue;
            }

            $op1 = $op['op1'];
            $op2 = $op['op2'];
            $res = $op['result'];
            $ext = $op['extended_value'];

            $opname = xcache_get_opcode($opc);

            if ($opname == 'UNDEF' || !isset($opname)) {
                echo '// UNDEF OP:';
                $this->dumpOp($op);
                continue;
            }
            // echo $i, ' '; $this->dumpOp($op); //var_dump($op);

            $resvar = null;
            unset($curResVar);
            if (array_key_exists($res['var'], $T)) {
                $curResVar = &$T[$res['var']];
            }
            if ((ZEND_ENGINE_2_4 ? ($res['op_type'] & EXT_TYPE_UNUSED) : ($res['EA.type'] & EXT_TYPE_UNUSED)) || $res['op_type'] == XC_IS_UNUSED) {
                $istmpres = false;
            }
            else {
                $istmpres = true;
            }
            // }}}
            // echo $opname, PHP_EOL;

            $notHandled = false;
            switch ($opc) {
                case XC_NEW: // {{{
                    array_push($this->EX['arg_types_stack'], array($this->EX['fbc'], $this->EX['object'], $this->EX['called_scope']));
                    $this->EX['object'] = $istmpres ? (int) $res['var'] : null;
                    $this->EX['called_scope'] = null;
                    $this->EX['fbc'] = new Decompiler_UnaryOp($this, $opc, $this->getOpVal($op1, false, true));
                    break;
                // }}}
                case XC_CATCH: // {{{
                    break;
                // }}}
                case XC_INSTANCEOF: // {{{
                    $resvar = new Decompiler_BinaryOp($this, $this->getOpVal($op1), $opc, $this->stripNamespace($this->getOpVal($op2)));
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
                        $istmpres = true;
                    }
                    else {
                        $class = $this->getOpVal($op2, true, true);
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
                        if (!ZEND_ENGINE_2) {
                            $resvar = $op1['constant'];
                            break;
                        }
                        $resvar = $this->stripNamespace($op1['constant']);
                    }
                    else {
                        $resvar = $this->getOpVal($op1);
                    }

                    $resvar = new Decompiler_Code($this, array($resvar, '::', unquoteName($this->getOpVal($op2))));
                    break;
                // }}}
                // {{{ case FETCH_*
                case XC_FETCH_R:
                case XC_FETCH_W:
                case XC_FETCH_RW:
                case XC_FETCH_FUNC_ARG:
                case XC_FETCH_UNSET:
                case XC_FETCH_IS:
                    $fetchType = defined('ZEND_FETCH_TYPE_MASK') ? ($ext & ZEND_FETCH_TYPE_MASK) : $op2[!ZEND_ENGINE_2 ? 'fetch_type' : 'EA.type'];
                    $name = isset($op1['constant']) ? $op1['constant'] : $this->getOpVal($op1);
                    if ($fetchType == ZEND_FETCH_STATIC_MEMBER) {
                        $class = $this->getOpVal($op2, false, true);
                    }
                    else {
                        $class = null;
                    }
                    $rvalue = new Decompiler_Fetch($this, $class, $name, $fetchType);

                    if ($res['op_type'] != XC_IS_UNUSED) {
                        $resvar = $rvalue;
                    }
                    break;
                // }}}
                case XC_UNSET_VAR: // {{{
                    $fetchType = defined('ZEND_FETCH_TYPE_MASK') ? ($ext & ZEND_FETCH_TYPE_MASK) : $op2['EA.type'];
                    if ($fetchType == ZEND_FETCH_STATIC_MEMBER) {
                        $class = isset($op2['constant']) ? $op2['constant'] /* PHP5.3- */ : $this->getOpVal($op2);
                        $rvalue = $this->stripNamespace($class) . '::$' . $op1['constant'];
                    }
                    else {
                        $rvalue = isset($op1['constant']) ? '$' . $op1['constant'] /* PHP5.1- */ : $this->getOpVal($op1);
                    }

                    $op['php'] = array("unset(", $rvalue, ")");
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
                    $src = $this->getOpVal($op1);
                    if (is_a($src, "Decompiler_ForeachBox")) {
                        assert($opc == XC_FETCH_DIM_TMP_VAR);
                        if (ZEND_ENGINE_2) {
                            $src = clone($src);
                        }
                        else {
                            $src = new Decompiler_ForeachBox($this, $src->obj);
                        }
                        $src->iskey = $op2['constant'];
                        $resvar = $src;
                        break;
                    }

                    if (is_a($src, "Decompiler_DimBox")) {
                        $dimbox = $src;
                    }
                    else {
                        if (!is_a($src, "Decompiler_ListBox")) {
                            $op1val = $this->getOpVal($op1);
                            $list = new Decompiler_List($this, isset($op1val) ? $op1val : '$this');

                            $src = new Decompiler_ListBox($this, $list);
                            if (!isset($op1['var'])) {
                                $this->dumpOp($op);
                                var_dump($op);
                                die('missing var');
                            }
                            $T[$op1['var']] = $src;
                            unset($list);
                        }
                        $dim = new Decompiler_Dim($this, $src);
                        $src->obj->dims[] = &$dim;

                        $dimbox = new Decompiler_DimBox($this, $dim);
                    }
                    $dim = &$dimbox->obj;
                    $dim->offsets[] = $this->getOpVal($op2);
                    /* TODO: use type mask */
                    if ($ext == ZEND_FETCH_ADD_LOCK) {
                        $src->obj->everLocked = true;
                    }
                    else if ($ext == ZEND_FETCH_STANDARD) {
                        $dim->isLast = true;
                    }
                    if ($opc == XC_UNSET_OBJ) {
                        $dim->isObject = true;
                    }
                    else if ($opc == XC_UNSET_DIM_OBJ) {
                        $dim->isObject = ZEND_ENGINE_2 ? $ext == ZEND_UNSET_OBJ : false /* cannot distingue */;
                    }
                    unset($dim);
                    $rvalue = $dimbox;
                    unset($dimbox);

                    if ($opc == XC_ASSIGN_DIM) {
                        $lvalue = $rvalue;
                        ++ $i;
                        $rvalue = $this->getOpVal($opcodes[$i]['op1']);
                        $resvar = new Decompiler_BinaryOp($this, $lvalue, $opc, $rvalue);
                    }
                    else if ($opc == XC_UNSET_DIM || $opc == XC_UNSET_OBJ || $opc == XC_UNSET_DIM_OBJ) {
                        $op['php'] = array("unset(", $rvalue, ")");
                        $lastphpop = &$op;
                    }
                    else if ($res['op_type'] != XC_IS_UNUSED) {
                        $resvar = $rvalue;
                    }
                    break;
                // }}}
                case XC_ASSIGN: // {{{
                    $lvalue = $this->getOpVal($op1);
                    $rvalue = $this->getOpVal($op2);
                    if (is_a($rvalue, 'Decompiler_ForeachBox')) {
                        $type = $rvalue->iskey ? 'fe_key' : 'fe_as';
                        $rvalue->obj[$type] = $lvalue;
                        unset($T[$op2['var']]);
                        break;
                    }
                    if (is_a($rvalue, "Decompiler_DimBox")) {
                        $dim = &$rvalue->obj;
                        $dim->assign = $lvalue;
                        if ($dim->isLast) {
                            $resvar = $dim->value;
                        }
                        unset($dim);
                        break;
                    }
                    if (is_a($lvalue, 'Decompiler_Fetch') && is_a($rvalue, 'Decompiler_Fetch')) {
                        if ($lvalue->name == $rvalue->name) {
                            switch ($rvalue->fetchType) {
                                case ZEND_FETCH_STATIC:
                                    $statics = &$this->EX['op_array']['static_variables'];
                                    if ((xcache_get_type($statics[$rvalue->name]) & IS_LEXICAL_VAR)) {
                                        $this->EX['uses'][] = $lvalue;
                                        unset($statics);
                                        break 2;
                                    }
                                    unset($statics);
                            }
                        }
                    }
                    $resvar = new Decompiler_BinaryOp($this, $lvalue, XC_ASSIGN, $rvalue);
                    break;
                // }}}
                case XC_ASSIGN_REF: // {{{
                    $lvalue = $this->getOpVal($op1);
                    $rvalue = $this->getOpVal($op2);
                    if (is_a($lvalue, 'Decompiler_Fetch') && is_a($rvalue, 'Decompiler_Fetch')) {
                        if ($lvalue->name == $rvalue->name) {
                            switch ($rvalue->fetchType) {
                                case ZEND_FETCH_GLOBAL:
                                case ZEND_FETCH_GLOBAL_LOCK:
                                    $resvar = new Decompiler_Code($this, array('global ', $lvalue));
                                    break 2;
                                case ZEND_FETCH_STATIC:
                                    $statics = &$this->EX['op_array']['static_variables'];
                                    if ((xcache_get_type($statics[$rvalue->name]) & IS_LEXICAL_REF)) {
                                        $this->EX['uses'][] = array('&', $lvalue);
                                        unset($statics);
                                        break 2;
                                    }

                                    $resvar = array();
                                    $resvar[] = 'static ';
                                    $resvar[] = $lvalue;
                                    if (isset($statics[$rvalue->name])) {
                                        $var = $statics[$rvalue->name];
                                        $resvar[] = ' = ';
                                        $resvar[] = value($var);
                                    }
                                    $resvar = new Decompiler_Code($this, $resvar);
                                    unset($statics);
                                    break 2;
                                default:
                            }
                        }
                    }
                    // TODO: PHP_6 global
                    $resvar = new Decompiler_BinaryOp($this, $lvalue, XC_ASSIGN_REF, $rvalue);
                    break;
                // }}}
                // {{{ case FETCH_OBJ_*
                case XC_FETCH_OBJ_R:
                case XC_FETCH_OBJ_W:
                case XC_FETCH_OBJ_RW:
                case XC_FETCH_OBJ_FUNC_ARG:
                case XC_FETCH_OBJ_UNSET:
                case XC_FETCH_OBJ_IS:
                case XC_ASSIGN_OBJ://对象赋值
                    $obj = $this->getOpVal($op1);
                    if (!isset($obj)) {
                        $obj = '$this';
                    }
                    $name = isset($op2['constant']) ? new Decompiler_Value($this, $op2['constant']) : $this->getOpVal($op2);
                    if ($res['op_type'] != XC_IS_UNUSED) {
                        $resvar = new Decompiler_Fetch($this, $obj, $name->value, XC_FETCH_PROPERTY);
                    }
                    if ($opc == XC_ASSIGN_OBJ) {
                        ++ $i;
                        $rvalue = (string)$resvar->scope."->".(string)$resvar->name;
                        $lvalue = $rvalue;
                        $rvalue = $this->getOpVal($opcodes[$i]['op1']);
                        $resvar = new Decompiler_BinaryOp($this, $lvalue, $opc, $rvalue);
                    }
                    break;
                // }}}
                case XC_ISSET_ISEMPTY_DIM_OBJ:
                case XC_ISSET_ISEMPTY_PROP_OBJ:
                case XC_ISSET_ISEMPTY:
                case XC_ISSET_ISEMPTY_VAR: // {{{
                    if ($opc == XC_ISSET_ISEMPTY_VAR) {
                        $rvalue = $this->getOpVal($op1);
                        // for < PHP_5_3
                        if ($op1['op_type'] == XC_IS_CONST) {
                            $rvalue = '$' . unquoteVariableName($this->getOpVal($op1));
                        }
                        $fetchtype = defined('ZEND_FETCH_TYPE_MASK') ? ($ext & ZEND_FETCH_TYPE_MASK) : $op2['EA.type'];
                        if ($fetchtype == ZEND_FETCH_STATIC_MEMBER) {
                            $class = isset($op2['constant']) ? $op2['constant'] : $this->getOpVal($op2);
                            $rvalue = $this->stripNamespace($class) . '::' . unquoteName($rvalue, $this->EX);
                        }
                    }
                    else if ($opc == XC_ISSET_ISEMPTY) {
                        $rvalue = $this->getOpVal($op1);
                    }
                    else {
                        $container = $this->getOpVal($op1);
                        $dim = $this->getOpVal($op2);
                        if ($opc == XC_ISSET_ISEMPTY_PROP_OBJ) {
                            if (!isset($container)) {
                                $container = '$this';
                            }
                            $rvalue = array($container, "->", unquoteVariableName($dim));
                        }
                        else {
                            $rvalue = array($container, '[', $dim, ']');
                        }
                    }

                    switch (((!ZEND_ENGINE_2 ? $op['op2']['var'] /* constant */ : $ext) & ZEND_ISSET_ISEMPTY_MASK)) {
                        case ZEND_ISSET:
                            $rvalue = array("isset(", $rvalue, ")");
                            break;
                        case ZEND_ISEMPTY:
                            $rvalue = array("empty(", $rvalue, ")");
                            break;
                    }
                    $resvar = new Decompiler_Code($this, $rvalue);
                    break;
                // }}}
                case XC_SEND_VAR_NO_REF:
                case XC_SEND_VAL:
                case XC_SEND_REF:
                case XC_SEND_VAR: // {{{
                    $ref = (!ZEND_ENGINE_2_4 && $opc == XC_SEND_REF ? '&' : '');
                    $this->EX['argstack'][] = array($ref, $this->getOpVal($op1));
                    break;
                // }}}
                case XC_INIT_STATIC_METHOD_CALL:
                case XC_INIT_METHOD_CALL: // {{{
                    array_push($this->EX['arg_types_stack'], array($this->EX['fbc'], $this->EX['object'], $this->EX['called_scope']));
                    if ($opc == XC_INIT_STATIC_METHOD_CALL) {
                        $this->EX['object'] = null;
                        $this->EX['called_scope'] = $this->getOpVal($op1, false, true);
                    }
                    else {
                        $obj = $this->getOpVal($op1);
                        if (!isset($obj)) {
                            $obj = '$this';
                        }
                        $this->EX['object'] = $obj;
                        $this->EX['called_scope'] = null;
                    }
                    if ($res['op_type'] != XC_IS_UNUSED) {
                        $resvar = '$obj call$';
                    }

                    $this->EX['fbc'] = isset($op2['constant']) ? $op2['constant'] : $this->getOpVal($op2);
                    if (!isset($this->EX['fbc'])) {
                        $this->EX['fbc'] = '__construct';
                    }
                    break;
                // }}}
                case XC_INIT_NS_FCALL_BY_NAME:
                case XC_INIT_FCALL_BY_NAME: // {{{
                    if (!ZEND_ENGINE_2 && ($ext & ZEND_CTOR_CALL)) {
                        break;
                    }
                    array_push($this->EX['arg_types_stack'], array($this->EX['fbc'], $this->EX['object'], $this->EX['called_scope']));
                    if (!ZEND_ENGINE_2 && ($ext & ZEND_MEMBER_FUNC_CALL)) {
                        if (isset($op1['constant'])) {
                            $this->EX['object'] = null;
                            $this->EX['called_scope'] = $this->stripNamespace($op1['constant']);
                        }
                        else {
                            $this->EX['object'] = $this->getOpVal($op1);
                            $this->EX['called_scope'] = null;
                        }
                    }
                    else {
                        $this->EX['object'] = null;
                        $this->EX['called_scope'] = null;
                    }
                    $this->EX['fbc'] = $this->getOpVal($op2, true, true);
                    break;
                // }}}
                case XC_INIT_FCALL_BY_FUNC: // {{{ deprecated even in PHP 4?
                    $this->EX['object'] = null;
                    $this->EX['called_scope'] = null;
                    $which = $op1['var'];
                    $this->EX['fbc'] = $this->EX['op_array']['funcs'][$which]['name'];
                    break;
                // }}}
                case XC_DO_FCALL_BY_FUNC:
                    $which = $op1['var'];
                    $fname = $this->EX['op_array']['funcs'][$which]['name'];
                    $args = $this->popargs($ext);
                    $resvar = new Decompiler_Code($this, array($fname, "(", $args, ")"));
                    break;
                case XC_DO_FCALL:
                    $fname = unquoteName($this->getOpVal($op1), $this->EX);
                    $args = $this->popargs($ext);
                    $resvar = new Decompiler_Code($this, array($fname, "(", $args, ")"));
                    break;
                case XC_DO_FCALL_BY_NAME: // {{{
                    $object = null;

                    if (!is_int($this->EX['object'])) {
                        $object = $this->EX['object'];
                    }

                    $code = array();
                    if (isset($object)) {
                        $code[] = $object;
                        $code[] = '->';
                    }
                    if (isset($this->EX['called_scope'])) {
                        $code[] = $this->EX['called_scope'];
                        $code[] = '::';
                    }
                    if (isset($this->EX['fbc'])) {
                        $code[] = $this->EX['fbc'];
                    }
                    $code[] = '(';
                    $code[] = $this->popargs($ext);
                    $code[] = ')';
                    $resvar = new Decompiler_Code($this, $code);
                    unset($code);

                    if (is_int($this->EX['object'])) {
                        $T[$this->EX['object']] = $resvar;
                        $resvar = null;
                    }
                    list($this->EX['fbc'], $this->EX['object'], $this->EX['called_scope']) = array_pop($this->EX['arg_types_stack']);
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
                    // possible missing tailing \0 (outside of the string)
                    $key = substr($key . ".", 0, strlen($key));
                    if (!isset($this->dc['class_table'][$key])) {
                        echo $this->EX['indent'], "/* class not found: ", $key, ", existing classes are:", PHP_EOL;
                        var_dump(array_keys($this->dc['class_table']));
                        echo "*/", PHP_EOL;
                        break;
                    }
                    $class = &$this->dc['class_table'][$key];
                    $this->detectNamespace($class['name']);

                    if (!isset($class['name'])) {
                        $class['name'] = unquoteName($this->getOpVal($op2), $this->EX);
                    }
                    if ($opc == XC_DECLARE_INHERITED_CLASS || $opc == XC_DECLARE_INHERITED_CLASS_DELAYED) {
                        if (ZEND_ENGINE_2_5) {
                            $ext = (0xffffffff - $ext + 1) / XC_SIZEOF_TEMP_VARIABLE - 1;
                        }
                        else {
                            $ext /= XC_SIZEOF_TEMP_VARIABLE;
                        }
                        if(isset($T[$ext])){
                            $class['parent'] = $T[$ext];//extends继承
                        }
                        unset($T[$ext]);
                    }
                    else {
                        $class['parent'] = null;
                    }

                    for (;;) {
                        if ($i + 1 <= $range[1]
                            && $opcodes[$i + 1]['opcode'] == XC_ADD_INTERFACE
                            && $opcodes[$i + 1]['op1']['var'] == $res['var']) {
                            // continue
                        }
                        else if ($i + 2 <= $range[1]
                            && $opcodes[$i + 2]['opcode'] == XC_ADD_INTERFACE
                            && $opcodes[$i + 2]['op1']['var'] == $res['var']
                            && $opcodes[$i + 1]['opcode'] == XC_FETCH_CLASS) {
                            // continue
                        }
                        else {
                            break;
                        }
                        $this->usedOps[XC_ADD_INTERFACE] = true;

                        $fetchop = &$opcodes[$i + 1];
                        $interface = $this->stripNamespace(unquoteName($this->getOpVal($fetchop['op2']), $this->EX));
                        $addop = &$opcodes[$i + 2];
                        $class['interfaces'][$addop['extended_value']] = $interface;
                        unset($fetchop, $addop);
                        $i += 2;
                    }
                    $this->activeClass = $class['name'];

                    $oldEX = &$this->EX;
                    unset($this->EX);
                    $this->dclass($class);
                    $this->EX = &$oldEX;
                    unset($oldEX);

                    $this->activeClass = null;
                    unset($class);
                    break;
                // }}}
                case XC_INIT_STRING: // {{{
                    $resvar = "''";
                    break;
                // }}}
                case XC_ADD_CHAR:
                case XC_ADD_STRING:
                case XC_ADD_VAR: // {{{
                    $op1val = $this->getOpVal($op1);
                    $op2val = $this->getOpVal($op2);
                    switch ($opc) {
                        case XC_ADD_CHAR:
                            $op2val = value(chr($op2val->value));
                            break;
                        case XC_ADD_STRING:
                            break;
                        case XC_ADD_VAR:
                            break;
                    }
                    if (!isset($op1val) == "''") {
                        $rvalue = $op2val;
                    }
                    else if (!isset($op2val) == "''") {
                        $rvalue = $op1val;
                    }
                    else {
                        $rvalue = new Decompiler_BinaryOp($this, $op1val, XC_CONCAT, $op2val);
                    }
                    $resvar = $rvalue;
                    // }}}
                    break;
                case XC_PRINT: // {{{
                    $op1val = $this->getOpVal($op1);
                    $resvar = new Decompiler_Code($this, array("print(", $op1val, ")"));
                    break;
                // }}}
                case XC_ECHO: // {{{
                    $op1val = $this->getOpVal($op1);
                    $resvar = new Decompiler_Code($this, array("echo ", $op1val));
                    break;
                // }}}
                case XC_EXIT: // {{{
                    $op1val = $this->getOpVal($op1);
                    $resvar = new Decompiler_Code($this, array("exit(", $op1val, ")"));
                    break;
                // }}}
                case XC_INIT_ARRAY:
                case XC_ADD_ARRAY_ELEMENT: // {{{
                    $rvalue = $this->getOpVal($op1, true);
                    $assoc = $this->getOpVal($op2);
                    $element = array($assoc, $rvalue, empty($ext) ? '' : '&');

                    if ($opc == XC_INIT_ARRAY) {
                        $resvar = new Decompiler_Array($this);

                        if (isset($rvalue)) {
                            $resvar->value[] = $element;
                        }
                    }
                    else {
                        $curResVar->value[] = $element;
                    }
                    unset($element);
                    break;
                // }}}
                case XC_QM_ASSIGN:
                case XC_QM_ASSIGN_VAR: // {{{
                    if (isset($curResVar) && is_a($curResVar, 'Decompiler_BinaryOp')) {
                        $curResVar->op2 = $this->getOpVal($op1);
                    }
                    else {
                        $resvar = $this->getOpVal($op1);
                    }
                    break;
                // }}}
                case XC_BOOL: // {{{
                    $resvar = /*'(bool) ' .*/ $this->getOpVal($op1);
                    break;
                // }}}
                case XC_GENERATOR_RETURN:
                case XC_RETURN_BY_REF:
                case XC_RETURN: // {{{
                    $resvar = new Decompiler_Code($this, array("return ", $this->getOpVal($op1)));
                    break;
                // }}}
                case XC_INCLUDE_OR_EVAL: // {{{
                    $type = ZEND_ENGINE_2_4 ? $ext : $op2['var']; // hack
                    $keyword = $this->includeTypes[$type];
                    $rvalue = $this->getOpVal($op1);
                    if ($type == ZEND_EVAL) {
                        $resvar = new Decompiler_Code($this, array($keyword, "(", $rvalue, ")"));
                    }
                    else {
                        $resvar = new Decompiler_Code($this, array($keyword, " ", $rvalue));
                    }
                    break;
                // }}}
                case XC_FE_RESET: // {{{
                    $resvar = $this->getOpVal($op1);
                    break;
                // }}}
                case XC_FE_FETCH: // {{{
                    $op['fe_src'] = $this->getOpVal($op1, true);
                    $fe = new Decompiler_ForeachBox($this, $op);
                    $fe->iskey = false;

                    if (ZEND_ENGINE_2_1) {
                        // save current first
                        $T[$res['var']] = $fe;

                        // move to next opcode
                        ++ $i;
                        assert($opcodes[$i]['opcode'] == XC_OP_DATA);
                        $fe = new Decompiler_ForeachBox($this, $op);
                        $fe->iskey = true;

                        $res = $opcodes[$i]['result'];
                    }

                    $resvar = $fe;
                    break;
                // }}}
                case XC_YIELD: // {{{
                    $resvar = new Decompiler_Code($this, array('yield ', $this->getOpVal($op1)));
                    break;
                // }}}
                case XC_SWITCH_FREE: // {{{
                    if (isset($T[$op1['var']])) {
                        $this->output->beginComplexBlock();
                        $this->output->writeln('switch (', $this->getOpVal($op1), ") {");
                        $this->output->writeln('}');
                        $this->output->endComplexBlock();
                    }
                    break;
                // }}}
                case XC_FREE: // {{{
                    $free = $T[$op1['var']];
                    if (!is_a($free, 'Decompiler_Box')) {
                        $op['php'] = is_object($free) || is_array($free) ? $free : $this->unquote($free, '(', ')');
                        $lastphpop = &$op;
                    }
                    unset($T[$op1['var']], $free);
                    break;
                // }}}
                case XC_JMP_NO_CTOR:
                    break;
                case XC_JMPZ_EX: // and
                case XC_JMPNZ_EX: // or
                    $resvar = $this->getOpVal($op1);
                    break;

                case XC_JMPNZ: // while
                case XC_JMPZNZ: // for
                case XC_JMPZ: // {{{
                    break;
                // }}}
                case XC_CONT:
                case XC_BRK:
                    $resvar = $opc == XC_CONT ? 'continue' : 'break';
                    $count = $this->getOpVal($op2);
                    if ($count->value != 1) {
                        $resvar .= ' ' . $count->value;
                    }
                    break;
                case XC_GOTO:
                    $resvar = 'goto label' . $op['op1']['var'];
                    $istmpres = false;
                    break;

                case XC_JMP: // {{{
                    break;
                // }}}
                case XC_CASE:
                    // $switchValue = $this->getOpVal($op1);
                    $caseValue = $this->getOpVal($op2);
                    $resvar = $caseValue;
                    break;
                case XC_RECV_INIT:
                case XC_RECV:
                    $offset = isset($op1['var']) ? $op1['var'] : $op1['constant'];
                    $lvalue = $this->getOpVal($op['result']);
                    if ($opc == XC_RECV_INIT) {
                        $default = value($op['op2']['constant']);
                    }
                    else {
                        $default = null;
                    }
                    $this->EX['recvs'][$offset] = array($lvalue, $default);
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
                        $code = array($this->getOpVal($op1), '->', $op2['constant']);
                    }
                    else {
                        $code = array($this->getOpVal($op1));
                    }
                    $opstr = isset($flags['DEC']) ? '--' : '++';
                    if (isset($flags['POST'])) {
                        $code[] = $opstr;
                    }
                    else {
                        array_unshift($code, $opstr);
                    }
                    $resvar = new Decompiler_Code($this, $code);
                    break;
                // }}}

                case XC_BEGIN_SILENCE: // {{{
                    $this->EX['silence']++;
                    break;
                // }}}
                case XC_END_SILENCE: // {{{
                    $this->EX['silence']--;
                    $lastresvar = new Decompiler_Code($this, array('@', $lastresvar));
                    break;
                // }}}
                case XC_CAST: // {{{
                    $type = $ext;
                    static $type2cast = array(
                        IS_LONG   => '(int)',
                        IS_DOUBLE => '(double)',
                        IS_STRING => '(string)',
                        IS_ARRAY  => '(array)',
                        IS_OBJECT => '(object)',
                        IS_BOOL   => '(bool)',
                        IS_NULL   => '(unset)',
                    );
                    assert(isset($type2cast[$type]));
                    $cast = $type2cast[$type];
                    $resvar = new Decompiler_Code($this, array($cast, ' ', $this->getOpVal($op1)));
                    break;
                // }}}
                case XC_EXT_STMT:
                case XC_EXT_FCALL_BEGIN:
                case XC_EXT_FCALL_END:
                case XC_EXT_NOP:
                case XC_INIT_CTOR_CALL:
                    break;
                case XC_DECLARE_FUNCTION:
                    $key = $op1['constant'];
                    // possible missing tailing \0 (outside of the string)
                    $key = substr($key . ".", 0, strlen($key));

                    $oldEX = &$this->EX;
                    unset($this->EX);
                    $this->dfunction($this->dc['function_table'][$key]);
                    $this->EX = $oldEX;

                    unset($oldEX);
                    break;
                case XC_DECLARE_LAMBDA_FUNCTION: // {{{
                    ob_start();
                    $key = $op1['constant'];
                    // possible missing tailing \0 (outside of the string)
                    $key = substr($key . ".", 0, strlen($key));

                    $oldEX = &$this->EX;
                    unset($this->EX);
                    $this->dfunction($this->dc['function_table'][$key]);
                    $this->EX = &$oldEX;
                    unset($oldEX);

                    $resvar = ob_get_clean();
                    $istmpres = true;
                    break;
                // }}}
                case XC_DECLARE_CONST:
                    $name = $this->stripNamespace(unquoteName($this->getOpVal($op1), $this->EX));
                    $value = $this->getOpVal($op2);
                    $resvar = new Decompiler_Code($this, array('const ', $name, ' = ', $value));
                    break;
                case XC_DECLARE_FUNCTION_OR_CLASS:
                    /* always removed by compiler */
                    break;
                case XC_TICKS:
                    $lastphpop['ticks'] = ZEND_ENGINE_2_4 ? $ext : ($op1 = $this->getOpVal($op1) ? $op1->value : 0);
                    // $this->EX['tickschanged'] = true;
                    break;
                case XC_RAISE_ABSTRACT_ERROR:
                    // abstract function body is empty, don't need this code
                    break;
                case XC_USER_OPCODE:
                    echo '// ZEND_USER_OPCODE, impossible to decompile  ';
                    break;
                case XC_OP_DATA:
                    break;
                default: // {{{
                    $call = array(&$this, $opname);
                    if (is_callable($call)) {
                        $this->usedOps[$opc] = true;
                        $this->{$opname}($op);
                    }
                    else if (isset($this->binaryOp[$opc])) { // {{{
                        $this->usedOps[$opc] = true;
                        $op1val = $this->getOpVal($op1);
                        $op2val = $this->getOpVal($op2);
                        $rvalue = new Decompiler_BinaryOp($this, $op1val, $opc, $op2val);
                        $resvar = $rvalue;
                        // }}}
                    }
                    else if (isset($this->unaryOp[$opc])) { // {{{
                        $this->usedOps[$opc] = true;
                        $op1val = $this->getOpVal($op1);
                        $resvar = new Decompiler_UnaryOp($this, $opc, $op1val);
                        // }}}
                    }
                    else {
                        $notHandled = true;
                    }
                // }}}
            }
            if ($notHandled) {
                echo $this->EX['indent'], "// TODO: ", $opname, PHP_EOL;
            }
            else {
                $this->usedOps[$opc] = true;
            }

            if (isset($resvar)) {
                if ($istmpres) {
                    $T[$res['var']] = $resvar;
                    $lastresvar = &$T[$res['var']];
                }
                else {
                    $op['php'] = $resvar;
                    $lastphpop = &$op;
                    $lastresvar = &$op['php'];
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
    function popargs($n) // {{{
    {
        $args = array();
        for ($i = 0; $i < $n; $i++) {
            $a = array_pop($this->EX['argstack']);
            array_unshift($args, $a);
        }
        return new Decompiler_Statements($this, $args);
    }
    // }}}
    function opValToString__($code) // {{{
    {
        while (is_object($code)) {
            $code = $code->toCode('');
        }

        if (is_array($code)) {
            foreach ($code as $c) {
                $this->opValToString__($c);
            }
        }
        else {
            echo $code;
        }
    }
    // }}}
    function opValToString_($op) // {{{
    {
        ob_start();
        $this->opValToString__($this->getOpVal($op, null));
        return ob_get_clean();
    }
    // }}}
    function opToString($op, $which) // {{{
    {
        switch ($op['op_type']) {
            case XC_IS_UNUSED:
                return '?' . $op['opline_num'];

            case XC_IS_VAR:
                $s = '$' . $op['var'];
                if ($which != 'result' && isset($this->EX['Ts'])) {
                    $s .= ':' . $this->opValToString_($op);
                }
                return $s;

            case XC_IS_TMP_VAR:
                $s = '#' . $op['var'];
                if ($which != 'result' && isset($this->EX['Ts'])) {
                    $s .= ':' . $this->opValToString_($op);
                }
                return $s;

            case XC_IS_CONST:
                return isset($this->EX['Ts']) ? $this->opValToString_($op) : $op['var'] . ':' . var_export($op['constant'], true);

            default:
                return isset($this->EX['Ts']) ? $this->opValToString_($op) : $op['op_type'] . '?' . $op['var'];
        }
    }
    // }}}
    function dumpOp($op, $padding = 4) // {{{
    {
        assert('isset($op)');
        $this->output->write(str_pad($op['line'], $padding));
        $this->output->write(str_pad($op['lineno'], $padding));

        if (isset($op['oldopcode'])) {
            $name = '//' . xcache_get_opcode($op['oldopcode']);
        }
        else {
            $name = xcache_get_opcode($op['opcode']);
        }

        if (substr($name, 0, 5) == 'ZEND_') {
            $name = substr($name, 5);
        }
        $this->output->write(' ', str_pad($name, 25));

        $types = array('result' => 9, 'op1' => 20, 'op2' => 20);
        $res = $op['result'];
        $resUsed = ((ZEND_ENGINE_2_4 ? ($res['op_type'] & EXT_TYPE_UNUSED) : ($res['EA.type'] & EXT_TYPE_UNUSED)) || $res['op_type'] == XC_IS_UNUSED) ? '' : '=';
        foreach ($types as $which => $len) {
            $this->output->write(' ', str_pad($this->opToString($op[$which], $which) . ($which == 'result' ? $resUsed : ''), $len));
        }
        $this->output->write("\t;", $op['extended_value']);
        if (isset($op['isCatchBegin'])) {
            $this->output->write(' CB');
        }
        if (!empty($op['jmptos'])) {
            $this->output->write("\t>>", implode(',', $op['jmptos']));
        }
        if (!empty($op['jmpfroms'])) {
            $this->output->write("\t<<", implode(',', $op['jmpfroms']));
        }

        $this->output->write(PHP_EOL);
    }
    // }}}
    function dumpRange($range, $ts = true) // {{{
    {
        $this->output->beginComment();
        if (!$ts) {
            $Ts = $this->EX['Ts'];
            $this->EX['Ts'] = null;
        }
        $padding = max(strlen($range[1]), strlen($this->EX['opcodes'][$range[1]]['lineno'])) + 1;
        for ($i = $range[0]; $i <= $range[1]; ++$i) {
            $this->output->write($this->output->indent);
            $this->dumpOp($this->EX['opcodes'][$i], $padding);
        }
        if (!$ts) {
            $this->EX['Ts'] = $Ts;
        }
        $this->output->endComment();
    }
    // }}}
    function dargs() // {{{
    {
        $op_array = &$this->EX['op_array'];

        if (isset($op_array['num_args'])) {
            $c = $op_array['num_args'];
        }
        else if (!empty($op_array['arg_types'])) {
            $c = count($op_array['arg_types']);
        }
        else {
            // php4
            $c = count($this->EX['recvs']);
        }

        $refrest = false;
        $args = array();
        for ($i = 0; $i < $c; $i++) {
            $arg = array();
            $recv = isset($this->EX['recvs'][$i + 1]) ? $this->EX['recvs'][$i + 1] : null;
            if (isset($op_array['arg_info'])) {
                $ai = $op_array['arg_info'][$i];
                if (isset($ai['type_hint']) ? ($ai['type_hint'] == IS_CALLABLE || $ai['type_hint'] == IS_OBJECT) : !empty($ai['class_name'])) {
                    $arg[] = $this->stripNamespace($ai['class_name']);
                    $arg[] = ' ';
                    if (!ZEND_ENGINE_2_2 && $ai['allow_null']) {
                        $arg[] = 'or NULL ';
                    }
                }
                else if (isset($ai['type_hint']) ? $ai['type_hint'] == IS_ARRAY : !empty($ai['array_type_hint'])) {
                    $arg[] = 'array ';
                    if (!ZEND_ENGINE_2_2 && $ai['allow_null']) {
                        $arg[] = 'or NULL ';
                    }
                }
                if ($ai['pass_by_reference']) {
                    $arg[] = '&';
                }
                $arg[] = '$';
                $arg[] = $ai['name'];
            }
            else {
                if ($refrest) {
                    $arg[] = '&';
                }
                else if (!empty($op_array['arg_types']) && isset($op_array['arg_types'][$i])) {
                    switch ($op_array['arg_types'][$i]) {
                        case BYREF_FORCE_REST:
                            $refrest = true;
                        /* fall */
                        case BYREF_FORCE:
                            $arg[] = '&';
                            break;

                        case BYREF_NONE:
                        case BYREF_ALLOW:
                            break;
                        default:
                            assert(0);
                    }
                }
                $arg[] = $recv[0];
            }
            if (isset($recv) && isset($recv[1])) {
                $arg[] = ' = ';
                $arg[] = $recv[1];
            }
            $args[] = $arg;
        }
        return new Decompiler_Statements($this, $args);
    }
    // }}}
    function duses() // {{{
    {
        $code = array();
        if ($this->EX['uses']) {
            $code[] = " use(";
            $code[] = new Decompiler_Statements($this, $this->EX['uses']);
            $code[] = ')';
        }
        return $code;
    }
    // }}}
    function dfunction($func, $decorations = array(), $nobody = false) // {{{
    {
        static $opcode_count = 0;
        $opcode_count += count($func['op_array']['opcodes']);

        $functionName = $this->stripNamespace($func['op_array']['function_name']);
        $this->detectNamespace($functionName);

        $isExpression = false;
        if ($functionName == '{closure}') {
            $functionName = '';
            $isExpression = true;
        }

        if (!$nobody && !$isExpression) {
            $this->output->beginComplexBlock();
        }

        $returnByRef = '';
        if ($nobody) {
            $EX = array();
            $EX['op_array'] = &$func['op_array'];
            $EX['recvs'] = array();
            $EX['uses'] = array();
        }
        else {
            ob_start();
            $this->output->beginScope();
            $EX = &$this->dop_array($func['op_array'], true);
            $this->output->endScope();
            $body = ob_get_clean();
            $hasReturn = false;
            $hasReturnByRef = false;
            foreach ($func['op_array']['opcodes'] as $op) {
                switch ($op['opcode']) {
                    case XC_RETURN:
                        $hasReturn = true;
                        break;

                    case XC_RETURN_BY_REF:
                        $hasReturnByRef = true;
                        break;
                }
            }
            if ($hasReturn && $hasReturnByRef) {
                $this->output->printfError("WARN: both return and return-by-ref present" . PHP_EOL);
            }
            if ($hasReturnByRef) {
                $returnByRef = '&';
            }
        }

        if (!empty($func['op_array']['doc_comment'])) {
            $this->output->writeln($func['op_array']['doc_comment']);
        }

        $functionDeclare = array();
        if ($decorations) {
            $functionDeclare[] = implode(' ', $decorations);
            $functionDeclare[] = ' ';
        }
        $this->EX = &$EX;
        unset($EX);
        $functionDeclare[] = 'function';
        $functionDeclare[] = $functionName ? ' ' . $returnByRef . $functionName : '';
        $functionDeclare[] = '(';
        $functionDeclare[] = $this->dargs();
        $functionDeclare[] = ")";
        $functionDeclare[] = $this->duses();
        unset($this->EX);
        if ($nobody) {
            $functionDeclare[] = ";";
            $this->output->writeln($functionDeclare);
        }
        else {
            if (!$isExpression) {
                $this->output->writeln($functionDeclare);
                $this->output->writeln("{");
            }
            else {
                $this->output->write($functionDeclare);
                $this->output->write(" {");
                $this->output->write(PHP_EOL);
            }
            echo $body;
            if (!$isExpression) {
                $this->output->writeln("}");
            }
            else {
                $this->output->write($this->output->indent);
                $this->output->write("}");
            }
            if (!$isExpression) {
                $this->output->endComplexBlock();
            }
        }

        if ($opcode_count > 10000) {
            $opcode_count = 0;
            if (function_exists("gc_collect_cycles")) {
                gc_collect_cycles();
            }
        }
    }
    // }}}
    function dclass($class) // {{{
    {
        $this->value2constant[$this->activeClass] = '__CLASS__';
        $this->detectNamespace($class['name']);

        // {{{ class decl
        $isInterface = false;
        $decorations = array();
        if (!empty($class['ce_flags'])) {
            if ($class['ce_flags'] & ZEND_ACC_INTERFACE) {
                $isInterface = true;
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

        $this->output->beginComplexBlock();
        if (!empty($class['doc_comment'])) {
            $this->output->writeln($class['doc_comment']);
        }
        $classDeclare = array();
        if ($decorations) {
            $classDeclare[] = implode(' ', $decorations) . ' ';
        }
        $classDeclare[] = $isInterface ? 'interface ' : 'class ';
        $classDeclare[] = $this->stripNamespace($class['name']);
        if ($class['parent']) {
            $classDeclare[] = ' extends ';
            $classDeclare[] = $this->stripNamespace($class['parent']);
        }
        /* TODO */
        if (!empty($class['interfaces'])) {
            $classDeclare[] = ' implements ';
            $classDeclare[] = implode(', ', $class['interfaces']);
        }
        $this->output->writeln($classDeclare);
        $this->output->writeln("{");
        $this->output->beginScope();
        // }}}
        // {{{ const
        if (!empty($class['constants_table'])) {
            $this->output->beginComplexBlock();
            foreach ($class['constants_table'] as $name => $v) {
                $this->output->writeln('const ', $name, ' = ', value($v), ";");
            }
            $this->output->endComplexBlock();
        }
        // }}}
        // {{{ properties
        if (ZEND_ENGINE_2 && !ZEND_ENGINE_2_4) {
            $default_static_members = $class[ZEND_ENGINE_2_1 ? 'default_static_members' : 'static_members'];
        }
        $member_variables = $class[ZEND_ENGINE_2 ? 'properties_info' : 'default_properties'];
        if ($member_variables) {
            $this->output->beginComplexBlock();
            foreach ($member_variables as $name => $dummy) {
                $info = isset($class['properties_info']) ? $class['properties_info'][$name] : null;
                if (isset($info) && !empty($info['doc_comment'])) {
                    $this->output->writeln($info['doc_comment']);
                }

                $variableDeclare = array();
                if (ZEND_ENGINE_2) {
                    $static = ($info['flags'] & ZEND_ACC_STATIC);

                    if ($static) {
                        $variableDeclare[] = "static ";
                    }
                }

                $mangleSuffix = '';
                if (!ZEND_ENGINE_2) {
                    $variableDeclare[] = 'var ';
                }
                else if (!isset($info)) {
                    $variableDeclare[] = 'public ';
                }
                else {
                    if ($info['flags'] & ZEND_ACC_SHADOW) {
                        continue;
                    }
                    switch ($info['flags'] & ZEND_ACC_PPP_MASK) {
                        case ZEND_ACC_PUBLIC:
                            $variableDeclare[] = "public ";
                            break;
                        case ZEND_ACC_PRIVATE:
                            $variableDeclare[] = "private ";
                            $mangleSuffix = "\000";
                            break;
                        case ZEND_ACC_PROTECTED:
                            $variableDeclare[] = "protected ";
                            $mangleSuffix = "\000";
                            break;
                    }
                }

                $variableDeclare[] = '$';
                $variableDeclare[] = $name;

                if (ZEND_ENGINE_2_4) {
                    $value = $class[$static ? 'default_static_members_table' : 'default_properties_table'][$info['offset']];
                }
                else if (!ZEND_ENGINE_2) {
                    $value = $class['default_properties'][$name];
                }
                else {
                    $key = $info['name'] . $mangleSuffix;
                    if ($static) {
                        $value = $default_static_members[$key];
                    }
                    else {
                        $value = $class['default_properties'][$key];
                    }
                }
                $value = value($value);
                if (is_a($value, 'Decompiler_Value') && !isset($value->value)) {
                    // skip value;
                }
                else {
                    $variableDeclare[] = ' = ';
                    $variableDeclare[] = $value;
                }
                $variableDeclare[] = ";";
                $this->output->writeln($variableDeclare);
            }
            $this->output->endComplexBlock();
        }
        // }}}
        // {{{ function_table
        if (isset($class['function_table'])) {
            foreach ($class['function_table'] as $func) {
                if (!isset($func['scope']) || $func['scope'] == $class['name']) {
                    // TODO: skip shadow here
                    $opa = &$func['op_array'];
                    $isAbstractMethod = false;
                    $decorations = array();
                    if (isset($opa['fn_flags'])) {
                        if (($opa['fn_flags'] & ZEND_ACC_ABSTRACT) && !$isInterface) {
                            $decorations[] = "abstract";
                            $isAbstractMethod = true;
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
                    $this->activeMethod = $this->activeClass . '::' . $opa['function_name'];
                    $this->activeFunction = $opa['function_name'];
                    $this->dfunction($func, $decorations, $isInterface || $isAbstractMethod);
                    $this->activeFunction = null;
                    $this->activeMethod = null;
                    if ($opa['function_name'] == 'Decompiler') {
                        //exit;
                    }
                }
            }
        }
        // }}}
        $this->output->endScope();
        $this->output->writeln("}");
        $this->output->endComplexBlock();
        unset($this->value2constant[$this->activeClass]);
    }
    // }}}
    function decompileString($string) // {{{
    {
        $this->dc = xcache_dasm_string($string);
        if ($this->dc === false) {
            echo "error compling string", PHP_EOL;
            return false;
        }
        $this->activeFile = null;
        $this->activeDir = null;
        return true;
    }
    // }}}
    function decompileFile($file) // {{{
    {
        if(is_file($file) === true){
            $this->dc = xcache_dasm_file($file);
            if ($this->dc === false) {
                echo "error compling $file", PHP_EOL;
                return false;
            }
            $this->activeFile = realpath($file);
            if (ZEND_ENGINE_2_3) {
                $this->activeDir = dirname($this->activeFile);
            }
            $this->value2constant[$this->activeFile] = '__FILE__';
            $this->value2constant[$this->activeDir] = '__DIR__';
            return true;
        }
        echo "file not foud!";
    }
    // }}}
    function decompileDasm($content) // {{{
    {
        $this->dc = $content;
        $this->activeFile = null;
        $this->activeDir = null;
        return true;
    }
    // }}}
    function output() // {{{
    {
        if (property_exists($this,'dc') && array_key_exists('op_array', $this->dc) ){

            $this->output->beginComplexBlock();
            $this->output->writeln("<" . "?php");
            $this->output->endComplexBlock();

            foreach ($this->dc['class_table'] as $key => $class) {
                if ($key{0} != "\0") {
                    $this->activeClass = $class['name'];
                    $this->dclass($class);
                    $this->activeClass = null;
                }
            }

            foreach ($this->dc['function_table'] as $key => $func) {
                if ($key{0} != "\0") {
                    $this->activeFunction = $key;
                    $this->dfunction($func);
                    $this->activeFunction = null;
                }
            }

            $this->dop_array($this->dc['op_array']);
            $this->output->beginComplexBlock();
            $this->output->writeln("?" . ">");
            $this->output->endComplexBlock();

            if (!empty($this->test)) {
                $this->outputUnusedOp();
            }
            return true;

        }
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

define('ZEND_ACC_SHADOW', 0x2000);

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
	exit;
}
//*/
foreach (array(
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
