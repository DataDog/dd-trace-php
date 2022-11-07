<?php

/** @generate-class-entries */

// Not every internally existing opcode is represented
// Some opcodes are purely technical, e.g. ZEND_CHECK_UNDEF_ARGS.
// Such opcodes are internally regenerated when re-assembling the op_array
// EXT_* (non-nop) ops are re-inserted automatically

namespace DDTrace;

enum OpArrayFlags {
    case Generator;
    case ReturnByRef;
}

class OpArray {
    /** @var OpArrayFlags[] */
    public array $flags;
    /** @var NamedVarNode[] */
    public array $vars;
    /** @var FunctionArgument[] */
    public array $args; // Used to rebuild RECV_* ops
    /** @var Op[] */
    public array $ops;
}

class FunctionArgument {
    public NamedVarNode $variable;
    public ?Ast $default = null;
    public bool $byRef = false;
    public bool $variadic = false;
}

interface OpNode {
}

class OpRefNode implements OpNode {
    public Op $target;
}

interface ValueNode extends OpNode {
}

class ConstNode extends ValueNode {
    public null|bool|int|double|string|array $value;
}

interface VarNode {
}

class NamedVarNode implements ValueNode, VarNode {
    public string $var;
}

interface StackNode extends ValueNode {
}

interface UnnamedVarNode extends StackNode, VarNode {
}

interface TmpNode extends StackNode {
}

class UnusedNode implements OpNode {
    public int $value;
}

class Op {
    public int $lineno;

    public function arg1(): OpNode;
    public function arg2(): OpNode;
    public function arg3(): OpNode;
}

class FetchTarget {}

class FetchTargetDim extends FetchTarget {
    public ValueNode $dim;
}

class FetchTargetObj extends FetchTarget {
    public ValueNode $prop; // Unused, aka $this is translated as NamedVarNode with "this" CV
}

class FetchTargetStaticProp extends FetchTarget {
    public ValueNode $prop;
}

class UnaryOp extends Op implements TmpNode {
    public ValueNode $arg;

    public function arg1(): OpNode;
}
class BinaryOp extends Op implements TmpNode {
    public ValueNode $arg1;
    public ValueNode $arg2;
    public ?FetchTarget $assign = null; // readwrite fetch

    public function arg1(): OpNode;
    public function arg2(): OpNode;
    public function arg3(): OpNode;
}
class IncDecOp extends Op implements TmpNode {
    public VarNode $var;
    public ?FetchTarget $target = null; // readwrite fetch

    public function arg1(): OpNode;
    public function arg2(): OpNode;
}
class JumpOp extends Op {
    public OpRefNode $target;

    public function arg1(): OpNode;
}

enum CastType {
    case Long;
    case Double;
    case String;
    case Bool;
    case Array;
    case Object;
}

enum IncludeMode {
    case IncludeOnce;
    case RequireOnce;
    case Include;
    case Require;
    case Eval;
}

class OpNop extends Op {
    public bool $ext = false;
}
class OpAdd extends BinaryOp {}
class OpSub extends BinaryOp {}
class OpMul extends BinaryOp {}
class OpDiv extends BinaryOp {}
class OpMod extends BinaryOp {}
class OpSl extends BinaryOp {}
class OpSr extends BinaryOp {}
class OpConcat extends BinaryOp {} // fast concat (incl. rope) is replaced by a series of concats
class OpBwOr extends BinaryOp {}
class OpBwAnd extends BinaryOp {}
class OpBwXor extends BinaryOp {}
class OpPow extends BinaryOp {}
class OpBwNot extends UnaryOp {}
class OpBoolNot extends UnaryOp {}
class OpBoolXor extends BinaryOp {}
class OpIsIdentical extends BinaryOp {}
class OpIsNotIdentical extends BinaryOp {}
class OpIsEqual extends BinaryOp {}
class OpIsNotEqual extends BinaryOp {}
class OpIsSmaller extends BinaryOp {}
class OpIsSmallerOrEqual extends BinaryOp {}
class OpSpaceship extends BinaryOp {}
class OpCast extends UnaryOp { // including ZEND_BOOL
    public CastType $type;
}
class OpAssign extends Op implements TmpNode {
    public VarNode $var;
    public FetchTarget $target; // write fetch
    public ValueNode $value;
    public bool $byRef = false;

    public function arg1(): OpNode;
    public function arg2(): OpNode;
    public function arg3(): OpNode;
}
class OpUnset extends Op {
    public VarNode $var; // needs specific fetchtarget if UnnamedVar
    public FetchTarget $target; // write fetch

    public function arg1(): OpNode;
    public function arg2(): OpNode;
}
class OpIsset extends Op {
    public VarNode $var; // needs specific fetchtarget if UnnamedVar
    public FetchTarget $target; // write fetch

    public function arg1(): OpNode;
    public function arg2(): OpNode;
}
class OpEmpty extends Op {
    public VarNode $var; // needs specific fetchtarget if UnnamedVar
    public FetchTarget $target; // write fetch

    public function arg1(): OpNode;
    public function arg2(): OpNode;
}
// Any series of FUNC_ARG fetches is preceded by ZEND_CHECK_FUNC_ARG
class OpFetchRead extends Op implements TmpNode {
    public ValueNode $var;
    public FetchTarget $target;
    public bool $byRef = false;
    // Covering _R and _IS; _IS if followed by coalesce or IssetIsempty
    // ZEND_FETCH_DIM_DIM ?
}
class OpFetchWrite extends Op implements UnnamedVarNode {
    public VarNode $var;
    public FetchTarget $target;
    // Covering _RW (followed by compaund op or incdec), _FUNC_ARG (followed by send op), _UNSET (followed by unset op) and _W (otherwise)
    // ZEND_FETCH_DIM_DIM ?
}
class OpFetchList extends Op implements StackNode {
    public VarNode $var;
    public ValueNode $dim;
    public bool $byRef = false; // write fetch if ref-fetch
}
class OpMakeRef extends Op implements UnnamedVarNode {
    public VarNode $var;
}
class OpQmAssign extends Op implements TmpNode {
    public ValueNode $arg;

    public function arg1(): OpNode;
}
class OpPreDec extends IncDecOp {}
class OpPreInc extends IncDecOp {}
class OpPostDec extends IncDecOp {}
class OpPostInc extends IncDecOp {}
class OpJmp extends JumpOp {}
class OpJmpz extends JumpOp {
    public ValueNode $condition;

    public function arg2(): OpNode;
}
class OpJmpnz extends JumpOp {
    public ValueNode $condition;

    public function arg2(): OpNode;
}
class OpJmpzEx extends JumpOp implements TmpNode {
    public ValueNode $condition;

    public function arg2(): OpNode;
}
class OpJmpnzEx extends JumpOp implements TmpNode {
    public ValueNode $condition;

    public function arg2(): OpNode;
}
class OpJmpNull extends JumpOp implements TmpNode {
    public ValueNode $condition;

    public function arg2(): OpNode;
}
// eventually followed by free
class OpCase extends Op implements TmpNode {
    public StackNode $switch;
    public ValueNode $compare;

    public function arg1(): OpNode;
    public function arg2(): OpNode;
}
// eventually followed by free
class OpCaseStrict extends Op implements TmpNode {
    public ValueNode $switch;
    public ValueNode $compare;

    public function arg1(): OpNode;
    public function arg2(): OpNode;
}
class OpMatchError extends Op {
    public ValueNode $switch;

    public function arg2(): OpNode;
}
class OpCheckVar extends Op {
    public NamedVarNode $var;

    public function arg1(): OpNode;
}
class OpInitFcall extends Op {
    public ValueNode $function; // const string is normal call, anything else dynamic call

    public function arg1(): OpNode;
}
class OpInitMethodCall extends Op {
    public ValueNode $object;
    public ValueNode $function;

    public function arg1(): OpNode;
    public function arg2(): OpNode;
}
class OpInitStaticMethodCall extends Op {
    public ValueNode $class;
    public ValueNode $function;

    public function arg1(): OpNode;
    public function arg2(): OpNode;
}
class OpNew extends Op implements TmpNode {
    public ValueNode $class;

    public function arg1(): OpNode;
}
// all send modes are coalesced into a single OpSend, to be reconstructed when reassembling
// SEND_USER/SEND_ARRAY are normalized into call_user_func equivalents
class OpSend extends Op {
    public ValueNode $value;

    public function arg1(): OpNode;
}
class OpSendUnpack extends Op {
    public ValueNode $value;

    public function arg1(): OpNode;
}
class OpDoFcall extends Op implements UnnamedVarNode {} // no ICALL/UCALL/BY_NAME specs
class OpCallableConvert extends Op implements TmpNode {}
class OpBeginSilence extends Op {}
class OpEndSilence extends Op {}
class OpReturn extends Op { // implicit verify_return_type before; also generator_return
    public ValueNode $arg;

    public function arg1(): OpNode;
}
class OpFree extends Op {
    public StackNode $arg;

    public function arg1(): OpNode;
}
class OpIncludeOrEval extends Op implements TmpNode {
    public ValueNode $arg;
    public IncludeMode $mode;

    public function arg1(): OpNode;
}
class OpInitArray extends Op implements TmpNode {
    public ?ValueNode $value = null;
    public ?ValueNode $key = null; // reject value if key null, but not value
    public bool $byRef = false;

    public function arg1(): OpNode;
    public function arg2(): OpNode;
}
class OpAddArrayElement extends Op {
    public ValueNode $value;
    public ?ValueNode $key = null;
    public bool $byRef = false;

    public OpInitArray $array; // must be before usage

    public function arg1(): OpNode;
    public function arg2(): OpNode;
    public function arg3(): OpNode;
}
class OpAddArrayUnpack extends Op {
    public ValueNode $arg;

    public OpInitArray $array; // must be before usage

    public function arg1(): OpNode;
    public function arg2(): OpNode;
}
class OpFeReset extends Op {
    public ValueNode $arg;
    public bool $byRef = false;

    public function arg1(): OpNode;
    public function arg2(): OpNode;
}
class OpFeFetch extends Op implements VarNode {
    public OpFeReset $arg;

    public function arg1(): OpNode;
    public function arg2(): OpNode;
}
class OpFeFree extends Op {
    public OpFeReset $arg;

    public function arg1(): OpNode;
}
class OpExit extends Op {
    public ?ValueNode $arg = null;

    public function arg1(): OpNode;
}
class OpFetchConstant extends Op implements TmpNode {
    public string $name;
    public bool $unqualified = false;

    public function arg1(): OpNode;
}
class OpFetchClassConstant extends Op implements TmpNode {
    public ValueNode $arg;
    public string $name;

    public function arg1(): OpNode;
    public function arg2(): OpNode;
}
// virtual opcode to model try/catch
class OpTry extends Op {}
// ZEND_LAST_CATCH if $afterCatch op is not catch
class OpCatch extends Op {
    public OpTry $try;
    public string $class;
    public Op $afterCatch;
    public ?NamedVarNode $var;

    public function arg1(): OpNode;
    public function arg2(): OpNode;
    public function arg3(): OpNode;
}
class OpFastCall extends JumpOp {}
class OpFastRet extends JumpOp {}
class OpThrow extends Op {
    public ValueNode $arg;

    public function arg1(): OpNode;
}
class OpClone extends Op implements TmpNode {
    public ValueNode $arg;

    public function arg1(): OpNode;
}
class OpEcho extends Op {
    public ValueNode $arg;

    public function arg1(): OpNode;
}
class OpInstanceof extends UnaryOp {
    public ValueNode $object;
    public ValueNode $class;

    public function arg1(): OpNode;
    public function arg2(): OpNode;
}
class OpDeclareFunction extends Op {
    public string $name;

    public function arg1(): OpNode;
}
class OpDeclareClass extends Op { // anon or delayed
    public string $name;

    public function arg1(): OpNode;
}
class OpAssertCheck extends JumpOp implements TmpNode {}
class OpJmpSet extends JumpOp implements TmpNode {
    public ValueNode $condition;

    public function arg2(): OpNode;
}
class OpSeparate extends Op implements UnnamedVarNode {
    public UnnamedVarNode $arg;

    public function arg1(): OpNode;
}
class OpFetchClassName extends Op implements TmpNode {
    public ValueNode $arg;

    public function arg1(): OpNode;
}
class OpYield extends Op implements TmpNode {
    public ?ValueNode $arg = null;
    public ?ValueNode $key = null;

    public function arg1(): OpNode;
    public function arg2(): OpNode;
}
class OpYieldFrom extends Op implements TmpNode {
    public ValueNode $arg;

    public function arg1(): OpNode;
}
class OpCopyTmp extends Op implements TmpNode {
    public TmpNode $arg;

    public function arg1(): OpNode;
}
class OpBindGlobal extends Op {
    public NamedVarNode $arg;
    public ConstNode $default;

    public function arg1(): OpNode;
    public function arg2(): OpNode;
}
class OpCoalesce extends JumpOp {
    public ValueNode $arg;

    public function arg2(): OpNode;
}
class OpBindLexical extends Op {
    public OpDeclareFunction $closure;
    public VarNode $var;
    public bool $byRef = false;

    public function arg1(): OpNode;
    public function arg2(): OpNode;
}
class OpBindStatic extends Op {
    public VarNode $var;

    public function arg1(): OpNode;
}

class Ast {}
class AstZval extends Ast {
    public mixed $value;
}
enum AstBinaryOp {
    case Add;
    case Sub;
    case Mul;
    case Pow;
    case Div;
    case Mod;
    case Sl;
    case Sr;
    case Concat;
    case IsIdentical;
    case IsNotIdentical;
    case IsEqual;
    case IsNotEqual;
    case IsSmaller;
    case IsSmallerOrEqual;
    case Spaceship;
    case BwOr;
    case BwAnd;
    case BwXor;
    case BoolXor;
    case BoolAnd;
    case BoolOr;
}
class AstBinary extends Ast {
    public AstBinaryOp $op;
    public Ast $arg1;
    public Ast $arg2;
}
enum AstUnaryOp {
    case Minus;
    case Plus;
    case BwNot;
    case BoolNot;
}
class AstUnary extends Ast {
    public AstUnaryOp $op;
    public Ast $arg;
}
class AstConstant extends Ast {
    public string $name;
}
class AstConstantClass extends Ast {}
class AstClassName extends Ast {
    public bool $ofParent = false; // otherwise self
}
class AstConditional extends Ast {
    public Ast $condition;
    public ?Ast $ifTrue = null;
    public Ast $ifFalse;
}
class AstCoalesce extends Ast {
    public Ast $value;
    public Ast $ifNull;
}
interface AstArrayElement {}
class AstArrayUnpack implements AstArrayElement {
    public Ast $array;
}
class AstArrayPair implements AstArrayElement {
    public ?Ast $key;
    public Ast $value;
}
class AstArray extends Ast {
    /** @var AstArrayElement[] */
    public array $elements;
}
class AstArrayDim extends Ast {
    public Ast $array;
    public Ast $dimension;
}
class AstClassConst extends Ast {
    public string $class;
    public string $name;
}
class AstNew extends Ast {
    public string $class;
    /** @var Ast[] */
    public array $args;
}
class AstProp extends Ast {
    public Ast $object;
    public Ast $prop;
    public bool $nullsafe = false;
}

function convert_op_array(string $method, ?string $class = null): ?OpArray {}
