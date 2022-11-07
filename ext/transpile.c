#include "transpile.h"
#include "ddtrace.h"
#include <Zend/zend_enum.h>
#include "transpile_arginfo.h"

static zend_class_entry *dd_ce_OpArrayFlags;
static zend_class_entry *dd_ce_OpArray;
static zend_class_entry *dd_ce_FunctionArgument;
static zend_class_entry *dd_ce_OpNode;
static zend_class_entry *dd_ce_OpRefNode;
static zend_class_entry *dd_ce_ValueNode;
static zend_class_entry *dd_ce_ConstNode;
static zend_class_entry *dd_ce_VarNode;
static zend_class_entry *dd_ce_NamedVarNode;
static zend_class_entry *dd_ce_StackNode;
static zend_class_entry *dd_ce_UnnamedVarNode;
static zend_class_entry *dd_ce_TmpNode;
static zend_class_entry *dd_ce_UnusedNode;
static zend_class_entry *dd_ce_Op;
static zend_class_entry *dd_ce_FetchTarget;
static zend_class_entry *dd_ce_FetchTargetDim;
static zend_class_entry *dd_ce_FetchTargetObj;
static zend_class_entry *dd_ce_FetchTargetStaticProp;
static zend_class_entry *dd_ce_UnaryOp;
static zend_class_entry *dd_ce_BinaryOp;
static zend_class_entry *dd_ce_IncDecOp;
static zend_class_entry *dd_ce_JumpOp;
static zend_class_entry *dd_ce_CastType;
static zend_class_entry *dd_ce_IncludeMode;
static zend_class_entry *dd_ce_OpNop;
static zend_class_entry *dd_ce_OpAdd;
static zend_class_entry *dd_ce_OpSub;
static zend_class_entry *dd_ce_OpMul;
static zend_class_entry *dd_ce_OpDiv;
static zend_class_entry *dd_ce_OpMod;
static zend_class_entry *dd_ce_OpSl;
static zend_class_entry *dd_ce_OpSr;
static zend_class_entry *dd_ce_OpConcat;
static zend_class_entry *dd_ce_OpBwOr;
static zend_class_entry *dd_ce_OpBwAnd;
static zend_class_entry *dd_ce_OpBwXor;
static zend_class_entry *dd_ce_OpPow;
static zend_class_entry *dd_ce_OpBwNot;
static zend_class_entry *dd_ce_OpBoolNot;
static zend_class_entry *dd_ce_OpBoolXor;
static zend_class_entry *dd_ce_OpIsIdentical;
static zend_class_entry *dd_ce_OpIsNotIdentical;
static zend_class_entry *dd_ce_OpIsEqual;
static zend_class_entry *dd_ce_OpIsNotEqual;
static zend_class_entry *dd_ce_OpIsSmaller;
static zend_class_entry *dd_ce_OpIsSmallerOrEqual;
static zend_class_entry *dd_ce_OpSpaceship;
static zend_class_entry *dd_ce_OpCast;
static zend_class_entry *dd_ce_OpAssign;
static zend_class_entry *dd_ce_OpUnset;
static zend_class_entry *dd_ce_OpIsset;
static zend_class_entry *dd_ce_OpEmpty;
static zend_class_entry *dd_ce_OpFetchRead;
static zend_class_entry *dd_ce_OpFetchWrite;
static zend_class_entry *dd_ce_OpFetchList;
static zend_class_entry *dd_ce_OpMakeRef;
static zend_class_entry *dd_ce_OpQmAssign;
static zend_class_entry *dd_ce_OpPreDec;
static zend_class_entry *dd_ce_OpPreInc;
static zend_class_entry *dd_ce_OpPostDec;
static zend_class_entry *dd_ce_OpPostInc;
static zend_class_entry *dd_ce_OpJmp;
static zend_class_entry *dd_ce_OpJmpz;
static zend_class_entry *dd_ce_OpJmpnz;
static zend_class_entry *dd_ce_OpJmpzEx;
static zend_class_entry *dd_ce_OpJmpnzEx;
static zend_class_entry *dd_ce_OpJmpNull;
static zend_class_entry *dd_ce_OpCase;
static zend_class_entry *dd_ce_OpCaseStrict;
static zend_class_entry *dd_ce_OpMatchError;
static zend_class_entry *dd_ce_OpCheckVar;
static zend_class_entry *dd_ce_OpInitFcall;
static zend_class_entry *dd_ce_OpInitMethodCall;
static zend_class_entry *dd_ce_OpInitStaticMethodCall;
static zend_class_entry *dd_ce_OpNew;
static zend_class_entry *dd_ce_OpSend;
static zend_class_entry *dd_ce_OpSendUnpack;
static zend_class_entry *dd_ce_OpDoFcall;
static zend_class_entry *dd_ce_OpCallableConvert;
static zend_class_entry *dd_ce_OpBeginSilence;
static zend_class_entry *dd_ce_OpEndSilence;
static zend_class_entry *dd_ce_OpReturn;
static zend_class_entry *dd_ce_OpFree;
static zend_class_entry *dd_ce_OpIncludeOrEval;
static zend_class_entry *dd_ce_OpInitArray;
static zend_class_entry *dd_ce_OpAddArrayElement;
static zend_class_entry *dd_ce_OpAddArrayUnpack;
static zend_class_entry *dd_ce_OpFeReset;
static zend_class_entry *dd_ce_OpFeFetch;
static zend_class_entry *dd_ce_OpFeFree;
static zend_class_entry *dd_ce_OpExit;
static zend_class_entry *dd_ce_OpFetchConstant;
static zend_class_entry *dd_ce_OpFetchClassConstant;
static zend_class_entry *dd_ce_OpTry;
static zend_class_entry *dd_ce_OpCatch;
static zend_class_entry *dd_ce_OpFastCall;
static zend_class_entry *dd_ce_OpFastRet;
static zend_class_entry *dd_ce_OpThrow;
static zend_class_entry *dd_ce_OpClone;
static zend_class_entry *dd_ce_OpEcho;
static zend_class_entry *dd_ce_OpInstanceof;
static zend_class_entry *dd_ce_OpDeclareFunction;
static zend_class_entry *dd_ce_OpDeclareClass;
static zend_class_entry *dd_ce_OpAssertCheck;
static zend_class_entry *dd_ce_OpJmpSet;
static zend_class_entry *dd_ce_OpSeparate;
static zend_class_entry *dd_ce_OpFetchClassName;
static zend_class_entry *dd_ce_OpYield;
static zend_class_entry *dd_ce_OpYieldFrom;
static zend_class_entry *dd_ce_OpCopyTmp;
static zend_class_entry *dd_ce_OpBindGlobal;
static zend_class_entry *dd_ce_OpCoalesce;
static zend_class_entry *dd_ce_OpBindLexical;
static zend_class_entry *dd_ce_OpBindStatic;

static zend_class_entry *dd_ce_Ast;
static zend_class_entry *dd_ce_AstZval;
static zend_class_entry *dd_ce_AstBinaryOp;
static zend_class_entry *dd_ce_AstBinary;
static zend_class_entry *dd_ce_AstUnaryOp;
static zend_class_entry *dd_ce_AstUnary;
static zend_class_entry *dd_ce_AstConstant;
static zend_class_entry *dd_ce_AstConstantClass;
static zend_class_entry *dd_ce_AstClassName;
static zend_class_entry *dd_ce_AstConditional;
static zend_class_entry *dd_ce_AstCoalesce;
static zend_class_entry *dd_ce_AstArrayElement;
static zend_class_entry *dd_ce_AstArrayUnpack;
static zend_class_entry *dd_ce_AstArrayPair;
static zend_class_entry *dd_ce_AstArray;
static zend_class_entry *dd_ce_AstArrayDim;
static zend_class_entry *dd_ce_AstClassConst;
static zend_class_entry *dd_ce_AstNew;
static zend_class_entry *dd_ce_AstProp;

void register_classes(void) {
	dd_ce_OpArrayFlags = register_class_DDTrace_OpArrayFlags();
	dd_ce_OpArray = register_class_DDTrace_OpArray();
	dd_ce_FunctionArgument = register_class_DDTrace_FunctionArgument();
	dd_ce_OpNode = register_class_DDTrace_OpNode();
	dd_ce_OpRefNode = register_class_DDTrace_OpRefNode(dd_ce_OpNode);
	dd_ce_ValueNode = register_class_DDTrace_ValueNode(dd_ce_OpNode);
	dd_ce_ConstNode = register_class_DDTrace_ConstNode(dd_ce_ValueNode);
	dd_ce_VarNode = register_class_DDTrace_VarNode();
	dd_ce_NamedVarNode = register_class_DDTrace_NamedVarNode(dd_ce_ValueNode, dd_ce_VarNode);
	dd_ce_StackNode = register_class_DDTrace_StackNode(dd_ce_ValueNode);
	dd_ce_UnnamedVarNode = register_class_DDTrace_UnnamedVarNode(dd_ce_StackNode, dd_ce_VarNode);
	dd_ce_TmpNode = register_class_DDTrace_TmpNode(dd_ce_StackNode);
	dd_ce_UnusedNode = register_class_DDTrace_UnusedNode(dd_ce_OpNode);
	dd_ce_Op = register_class_DDTrace_Op();
	dd_ce_FetchTarget = register_class_DDTrace_FetchTarget();
	dd_ce_FetchTargetDim = register_class_DDTrace_FetchTargetDim(dd_ce_FetchTarget);
	dd_ce_FetchTargetObj = register_class_DDTrace_FetchTargetObj(dd_ce_FetchTarget);
	dd_ce_FetchTargetStaticProp = register_class_DDTrace_FetchTargetStaticProp(dd_ce_FetchTarget);
	dd_ce_UnaryOp = register_class_DDTrace_UnaryOp(dd_ce_Op, dd_ce_TmpNode);
	dd_ce_BinaryOp = register_class_DDTrace_BinaryOp(dd_ce_Op, dd_ce_TmpNode);
	dd_ce_IncDecOp = register_class_DDTrace_IncDecOp(dd_ce_Op, dd_ce_TmpNode);
	dd_ce_JumpOp = register_class_DDTrace_JumpOp(dd_ce_Op);
	dd_ce_CastType = register_class_DDTrace_CastType();
	dd_ce_IncludeMode = register_class_DDTrace_IncludeMode();
	dd_ce_OpNop = register_class_DDTrace_OpNop(dd_ce_Op);
	dd_ce_OpAdd = register_class_DDTrace_OpAdd(dd_ce_BinaryOp);
	dd_ce_OpSub = register_class_DDTrace_OpSub(dd_ce_BinaryOp);
	dd_ce_OpMul = register_class_DDTrace_OpMul(dd_ce_BinaryOp);
	dd_ce_OpDiv = register_class_DDTrace_OpDiv(dd_ce_BinaryOp);
	dd_ce_OpMod = register_class_DDTrace_OpMod(dd_ce_BinaryOp);
	dd_ce_OpSl = register_class_DDTrace_OpSl(dd_ce_BinaryOp);
	dd_ce_OpSr = register_class_DDTrace_OpSr(dd_ce_BinaryOp);
	dd_ce_OpConcat = register_class_DDTrace_OpConcat(dd_ce_BinaryOp);
	dd_ce_OpBwOr = register_class_DDTrace_OpBwOr(dd_ce_BinaryOp);
	dd_ce_OpBwAnd = register_class_DDTrace_OpBwAnd(dd_ce_BinaryOp);
	dd_ce_OpBwXor = register_class_DDTrace_OpBwXor(dd_ce_BinaryOp);
	dd_ce_OpPow = register_class_DDTrace_OpPow(dd_ce_BinaryOp);
	dd_ce_OpBwNot = register_class_DDTrace_OpBwNot(dd_ce_UnaryOp);
	dd_ce_OpBoolNot = register_class_DDTrace_OpBoolNot(dd_ce_UnaryOp);
	dd_ce_OpBoolXor = register_class_DDTrace_OpBoolXor(dd_ce_BinaryOp);
	dd_ce_OpIsIdentical = register_class_DDTrace_OpIsIdentical(dd_ce_BinaryOp);
	dd_ce_OpIsNotIdentical = register_class_DDTrace_OpIsNotIdentical(dd_ce_BinaryOp);
	dd_ce_OpIsEqual = register_class_DDTrace_OpIsEqual(dd_ce_BinaryOp);
	dd_ce_OpIsNotEqual = register_class_DDTrace_OpIsNotEqual(dd_ce_BinaryOp);
	dd_ce_OpIsSmaller = register_class_DDTrace_OpIsSmaller(dd_ce_BinaryOp);
	dd_ce_OpIsSmallerOrEqual = register_class_DDTrace_OpIsSmallerOrEqual(dd_ce_BinaryOp);
	dd_ce_OpSpaceship = register_class_DDTrace_OpSpaceship(dd_ce_BinaryOp);
	dd_ce_OpCast = register_class_DDTrace_OpCast(dd_ce_UnaryOp);
	dd_ce_OpAssign = register_class_DDTrace_OpAssign(dd_ce_Op, dd_ce_TmpNode);
	dd_ce_OpUnset = register_class_DDTrace_OpUnset(dd_ce_Op);
	dd_ce_OpIsset = register_class_DDTrace_OpIsset(dd_ce_Op);
	dd_ce_OpEmpty = register_class_DDTrace_OpEmpty(dd_ce_Op);
	dd_ce_OpFetchRead = register_class_DDTrace_OpFetchRead(dd_ce_Op, dd_ce_StackNode);
	dd_ce_OpFetchWrite = register_class_DDTrace_OpFetchWrite(dd_ce_Op, dd_ce_StackNode);
	dd_ce_OpFetchList = register_class_DDTrace_OpFetchList(dd_ce_Op, dd_ce_StackNode);
	dd_ce_OpMakeRef = register_class_DDTrace_OpMakeRef(dd_ce_Op, dd_ce_UnnamedVarNode);
	dd_ce_OpQmAssign = register_class_DDTrace_OpQmAssign(dd_ce_Op, dd_ce_TmpNode);
	dd_ce_OpPreDec = register_class_DDTrace_OpPreDec(dd_ce_IncDecOp);
	dd_ce_OpPreInc = register_class_DDTrace_OpPreInc(dd_ce_IncDecOp);
	dd_ce_OpPostDec = register_class_DDTrace_OpPostDec(dd_ce_IncDecOp);
	dd_ce_OpPostInc = register_class_DDTrace_OpPostInc(dd_ce_IncDecOp);
	dd_ce_OpJmp = register_class_DDTrace_OpJmp(dd_ce_JumpOp);
	dd_ce_OpJmpz = register_class_DDTrace_OpJmpz(dd_ce_JumpOp);
	dd_ce_OpJmpnz = register_class_DDTrace_OpJmpnz(dd_ce_JumpOp);
	dd_ce_OpJmpzEx = register_class_DDTrace_OpJmpzEx(dd_ce_JumpOp, dd_ce_TmpNode);
	dd_ce_OpJmpnzEx = register_class_DDTrace_OpJmpnzEx(dd_ce_JumpOp, dd_ce_TmpNode);
	dd_ce_OpJmpNull = register_class_DDTrace_OpJmpNull(dd_ce_JumpOp, dd_ce_TmpNode);
	dd_ce_OpCase = register_class_DDTrace_OpCase(dd_ce_Op, dd_ce_TmpNode);
	dd_ce_OpCaseStrict = register_class_DDTrace_OpCaseStrict(dd_ce_Op, dd_ce_TmpNode);
	dd_ce_OpMatchError = register_class_DDTrace_OpMatchError(dd_ce_Op);
	dd_ce_OpCheckVar = register_class_DDTrace_OpCheckVar(dd_ce_Op);
	dd_ce_OpInitFcall = register_class_DDTrace_OpInitFcall(dd_ce_Op);
	dd_ce_OpInitMethodCall = register_class_DDTrace_OpInitMethodCall(dd_ce_Op);
	dd_ce_OpInitStaticMethodCall = register_class_DDTrace_OpInitStaticMethodCall(dd_ce_Op);
	dd_ce_OpNew = register_class_DDTrace_OpNew(dd_ce_Op, dd_ce_TmpNode);
	dd_ce_OpSend = register_class_DDTrace_OpSend(dd_ce_Op);
	dd_ce_OpSendUnpack = register_class_DDTrace_OpSendUnpack(dd_ce_Op);
	dd_ce_OpDoFcall = register_class_DDTrace_OpDoFcall(dd_ce_Op, dd_ce_UnnamedVarNode);
	dd_ce_OpCallableConvert = register_class_DDTrace_OpCallableConvert(dd_ce_Op, dd_ce_TmpNode);
	dd_ce_OpBeginSilence = register_class_DDTrace_OpBeginSilence(dd_ce_Op);
	dd_ce_OpEndSilence = register_class_DDTrace_OpEndSilence(dd_ce_Op);
	dd_ce_OpReturn = register_class_DDTrace_OpReturn(dd_ce_Op);
	dd_ce_OpFree = register_class_DDTrace_OpFree(dd_ce_Op);
	dd_ce_OpIncludeOrEval = register_class_DDTrace_OpIncludeOrEval(dd_ce_Op, dd_ce_TmpNode);
	dd_ce_OpInitArray = register_class_DDTrace_OpInitArray(dd_ce_Op, dd_ce_TmpNode);
	dd_ce_OpAddArrayElement = register_class_DDTrace_OpAddArrayElement(dd_ce_Op);
	dd_ce_OpAddArrayUnpack = register_class_DDTrace_OpAddArrayUnpack(dd_ce_Op);
	dd_ce_OpFeReset = register_class_DDTrace_OpFeReset(dd_ce_Op);
	dd_ce_OpFeFetch = register_class_DDTrace_OpFeFetch(dd_ce_Op, dd_ce_VarNode);
	dd_ce_OpFeFree = register_class_DDTrace_OpFeFree(dd_ce_Op);
	dd_ce_OpExit = register_class_DDTrace_OpExit(dd_ce_Op);
	dd_ce_OpFetchConstant = register_class_DDTrace_OpFetchConstant(dd_ce_Op, dd_ce_TmpNode);
	dd_ce_OpFetchClassConstant = register_class_DDTrace_OpFetchClassConstant(dd_ce_Op, dd_ce_TmpNode);
	dd_ce_OpTry = register_class_DDTrace_OpTry(dd_ce_Op);
	dd_ce_OpCatch = register_class_DDTrace_OpCatch(dd_ce_Op);
	dd_ce_OpFastCall = register_class_DDTrace_OpFastCall(dd_ce_JumpOp);
	dd_ce_OpFastRet = register_class_DDTrace_OpFastRet(dd_ce_JumpOp);
	dd_ce_OpThrow = register_class_DDTrace_OpThrow(dd_ce_Op);
	dd_ce_OpClone = register_class_DDTrace_OpClone(dd_ce_Op, dd_ce_TmpNode);
	dd_ce_OpEcho = register_class_DDTrace_OpEcho(dd_ce_Op);
	dd_ce_OpInstanceof = register_class_DDTrace_OpInstanceof(dd_ce_UnaryOp);
	dd_ce_OpDeclareFunction = register_class_DDTrace_OpDeclareFunction(dd_ce_Op);
	dd_ce_OpDeclareClass = register_class_DDTrace_OpDeclareClass(dd_ce_Op);
	dd_ce_OpAssertCheck = register_class_DDTrace_OpAssertCheck(dd_ce_JumpOp, dd_ce_TmpNode);
	dd_ce_OpJmpSet = register_class_DDTrace_OpJmpSet(dd_ce_JumpOp, dd_ce_TmpNode);
	dd_ce_OpSeparate = register_class_DDTrace_OpSeparate(dd_ce_Op, dd_ce_UnnamedVarNode);
	dd_ce_OpFetchClassName = register_class_DDTrace_OpFetchClassName(dd_ce_Op, dd_ce_TmpNode);
	dd_ce_OpYield = register_class_DDTrace_OpYield(dd_ce_Op, dd_ce_TmpNode);
	dd_ce_OpYieldFrom = register_class_DDTrace_OpYieldFrom(dd_ce_Op, dd_ce_TmpNode);
	dd_ce_OpCopyTmp = register_class_DDTrace_OpCopyTmp(dd_ce_Op, dd_ce_TmpNode);
	dd_ce_OpBindGlobal = register_class_DDTrace_OpBindGlobal(dd_ce_Op);
	dd_ce_OpCoalesce = register_class_DDTrace_OpCoalesce(dd_ce_JumpOp);
	dd_ce_OpBindLexical = register_class_DDTrace_OpBindLexical(dd_ce_Op);
	dd_ce_OpBindStatic = register_class_DDTrace_OpBindStatic(dd_ce_Op);

	dd_ce_Ast = register_class_DDTrace_Ast();
	dd_ce_AstZval = register_class_DDTrace_AstZval(dd_ce_Ast);
	dd_ce_AstBinaryOp = register_class_DDTrace_AstBinaryOp();
	dd_ce_AstBinary = register_class_DDTrace_AstBinary(dd_ce_Ast);
	dd_ce_AstUnaryOp = register_class_DDTrace_AstUnaryOp();
	dd_ce_AstUnary = register_class_DDTrace_AstUnary(dd_ce_Ast);
	dd_ce_AstConstant = register_class_DDTrace_AstConstant(dd_ce_Ast);
	dd_ce_AstConstantClass = register_class_DDTrace_AstConstantClass(dd_ce_Ast);
	dd_ce_AstClassName = register_class_DDTrace_AstClassName(dd_ce_Ast);
	dd_ce_AstConditional = register_class_DDTrace_AstConditional(dd_ce_Ast);
	dd_ce_AstCoalesce = register_class_DDTrace_AstCoalesce(dd_ce_Ast);
	dd_ce_AstArrayElement = register_class_DDTrace_AstArrayElement();
	dd_ce_AstArrayUnpack = register_class_DDTrace_AstArrayUnpack(dd_ce_AstArrayElement);
	dd_ce_AstArrayPair = register_class_DDTrace_AstArrayPair(dd_ce_AstArrayElement);
	dd_ce_AstArray = register_class_DDTrace_AstArray(dd_ce_Ast);
	dd_ce_AstArrayDim = register_class_DDTrace_AstArrayDim(dd_ce_Ast);
	dd_ce_AstClassConst = register_class_DDTrace_AstClassConst(dd_ce_Ast);
	dd_ce_AstNew = register_class_DDTrace_AstNew(dd_ce_Ast);
	dd_ce_AstProp = register_class_DDTrace_AstProp(dd_ce_Ast);
}

static inline void zend_update_property_obj(zend_class_entry *scope, zend_object *object, const char *name, size_t name_length, zend_object *value) {
	zval tmp;

	ZVAL_OBJ(&tmp, value);
	zend_update_property(scope, object, name, name_length, &tmp);
}

#define PROP_TYPE(_, arg, ...) arg
#define CONC(a, b) a ## b
#define CONC_EXP(a, b) CONC(a, b)
#define PROP(name, val, ...) CONC_EXP(zend_update_property, PROP_TYPE(,##__VA_ARGS__,))(obj->ce, obj, ZEND_STRL(name), val)

static zend_object *dd_convert_ast(zend_ast *ast) {
	zend_object *obj;
	switch (ast->kind) {
		case ZEND_AST_GREATER:;
			const char *binary_op = "IsSmaller";
			if (0) {
				case ZEND_AST_GREATER_EQUAL:
					binary_op = "IsSmallerOrEqual";
			}
			if (0) {
				case ZEND_AST_AND:
					binary_op = "BoolAnd";
			}
			if (0) {
				case ZEND_AST_OR:
					binary_op = "BoolOr";
			}
			zend_ast *arg1 = ast->child[1];
			zend_ast *arg2 = ast->child[0];
			if (0) {
				case ZEND_AST_BINARY_OP:
					switch (ast->attr) {
						case ZEND_ADD: binary_op = "Add"; break;
						case ZEND_SUB: binary_op = "Sub"; break;
						case ZEND_MUL: binary_op = "Mul"; break;
						case ZEND_POW: binary_op = "Pow"; break;
						case ZEND_DIV: binary_op = "Div"; break;
						case ZEND_MOD: binary_op = "Mod"; break;
						case ZEND_SL: binary_op = "Sl"; break;
						case ZEND_SR: binary_op = "Sr"; break;
						case ZEND_FAST_CONCAT: binary_op = "Concat"; break;
						case ZEND_IS_IDENTICAL: binary_op = "IsIdentical"; break;
						case ZEND_IS_NOT_IDENTICAL: binary_op = "IsNotIdentical"; break;
						case ZEND_IS_EQUAL: binary_op = "IsEqual"; break;
						case ZEND_IS_NOT_EQUAL: binary_op = "IsNotEqual"; break;
						case ZEND_IS_SMALLER: binary_op = "IsSmaller"; break;
						case ZEND_IS_SMALLER_OR_EQUAL: binary_op = "IsSmallerOrEqual"; break;
						case ZEND_SPACESHIP: binary_op = "Spaceship"; break;
						case ZEND_BW_OR: binary_op = "BwOr"; break;
						case ZEND_BW_AND: binary_op = "BwAnd"; break;
						case ZEND_BW_XOR: binary_op = "BwXor"; break;
						case ZEND_BOOL_XOR: binary_op = "BoolXor"; break;
					}
					arg1 = ast->child[0];
					arg2 = ast->child[1];
			}
			obj = zend_objects_new(dd_ce_AstBinary);
			zend_object *binary_op_enum = zend_enum_get_case_cstr(dd_ce_AstBinaryOp, binary_op);
			GC_ADDREF(binary_op_enum);
			PROP("op", binary_op_enum, _obj);
			PROP("arg1", dd_convert_ast(arg1), _obj);
			PROP("arg2", dd_convert_ast(arg2), _obj);
			break;

		case ZEND_AST_UNARY_PLUS:;
			const char *unary_op = "Plus";
			if (0) {
				case ZEND_AST_UNARY_MINUS:
					unary_op = "Minus";
			}
			if (0) {
				case ZEND_AST_UNARY_OP:
					switch (ast->attr) {
						case ZEND_BW_NOT: unary_op = "BwNot";
						case ZEND_BW_OR: unary_op = "BwOr";
					}
			}
			obj = zend_objects_new(dd_ce_AstBinary);
			zend_object *unary_op_enum = zend_enum_get_case_cstr(dd_ce_AstUnaryOp, unary_op);
			GC_ADDREF(unary_op_enum);
			PROP("op", unary_op_enum, _obj);
			PROP("arg", dd_convert_ast(ast->child[0]), _obj);
			break;

		case ZEND_AST_ZVAL:
			obj = zend_objects_new(dd_ce_AstZval);
			zval *zvp = zend_ast_get_zval(ast);
			Z_TRY_ADDREF_P(zvp);
			PROP("value", zvp);
			break;

		case ZEND_AST_CONSTANT:
			obj = zend_objects_new(dd_ce_AstConstant);
			PROP("name", zend_string_copy(zend_ast_get_constant_name(ast)), _str);
			break;

		case ZEND_AST_CONSTANT_CLASS:
			obj = zend_objects_new(dd_ce_AstConstantClass);
			break;

		case ZEND_AST_CLASS_NAME:
			obj = zend_objects_new(dd_ce_AstClassName);
			if (ast->attr == ZEND_FETCH_CLASS_PARENT) {
				PROP("ofParent", true, _bool);
			}
			break;

		case ZEND_AST_CONDITIONAL:
			obj = zend_objects_new(dd_ce_AstConditional);
			PROP("condition", dd_convert_ast(ast->child[0]), _obj);
			if (ast->child[1]) {
				PROP("ifTrue", dd_convert_ast(ast->child[1]), _obj);
			}
			PROP("ifFalse", dd_convert_ast(ast->child[2]), _obj);
			break;

		case ZEND_AST_COALESCE:
			obj = zend_objects_new(dd_ce_AstCoalesce);
			PROP("value", dd_convert_ast(ast->child[0]), _obj);
			PROP("ifNull", dd_convert_ast(ast->child[1]), _obj);
			break;

		case ZEND_AST_ARRAY:;
			zend_ast_list *array_list = zend_ast_get_list(ast);
			zval zvarr;
			array_init_size(&zvarr, array_list->children);
			for (int i = 0; i < array_list->children; ++i) {
				zend_ast *elem = array_list->child[i];
				if (elem->kind == ZEND_AST_UNPACK) {
					obj = zend_objects_new(dd_ce_AstArrayUnpack);
					PROP("array", dd_convert_ast(ast->child[0]), _obj);
				} else {
					obj = zend_objects_new(dd_ce_AstArrayPair);
					PROP("value", dd_convert_ast(ast->child[0]), _obj);
					if (ast->child[1]) {
						PROP("key", dd_convert_ast(ast->child[1]), _obj);
					}
				}
				add_next_index_object(&zvarr, obj);
			}
			obj = zend_objects_new(dd_ce_AstArray);
			PROP("elements", &zvarr);
			break;

		case ZEND_AST_DIM:
			obj = zend_objects_new(dd_ce_AstArrayDim);
			PROP("array", dd_convert_ast(ast->child[0]), _obj);
			PROP("dimension", dd_convert_ast(ast->child[1]), _obj);
			break;

		case ZEND_AST_CLASS_CONST:
			obj = zend_objects_new(dd_ce_AstClassConst);
			PROP("class", zend_string_copy(zend_ast_get_str(ast->child[0])), _str);
			PROP("name", zend_string_copy(zend_ast_get_str(ast->child[1])), _str);
			break;

		case ZEND_AST_NEW:;
			zend_ast_list *args_list = zend_ast_get_list(ast->child[1]);
			zval zvargs;
			array_init_size(&zvargs, args_list->children);
			for (int i = 0; i < args_list->children; ++i) {
				add_next_index_object(&zvarr, dd_convert_ast(args_list->child[i]));
			}
			obj = zend_objects_new(dd_ce_AstNew);
			PROP("class", zend_string_copy(zend_ast_get_str(ast->child[0])), _str);
			PROP("args", &zvargs);
			break;

		case ZEND_AST_PROP:
		case ZEND_AST_NULLSAFE_PROP:
			obj = zend_objects_new(dd_ce_AstProp);
			PROP("object", dd_convert_ast(ast->child[0]), _obj);
			PROP("prop", dd_convert_ast(ast->child[1]), _obj);
			if (ast->kind == ZEND_AST_NULLSAFE_PROP) {
				PROP("nullsafe", true, _bool);
			}
			break;

	}

	return obj;
}

#define BINARY_OP 0x1
#define UNARY_OP 0x2
#define FETCH_FLAG 0x4
#define FETCH_DIM (FETCH_FLAG | 0x8)
#define FETCH_OBJ (FETCH_FLAG | 0x10)
#define FETCH_STATIC_PROP (FETCH_DIM | FETCH_OBJ)
#define REF_FLAG 0x20
#define VAR_FIRST 0x40
#define CONDITIONAL_JMP 0x80
#define SEND_OP 0x100
#define FCALL_INIT 0x200
#define ISSET_ISEMPTY 0x400
#define DECLARE_CLASS 0x800
#define ADD_ARRAY 0x1000
#define SKIP_RESULT_REASSIGN 0x2000
#define ASSIGN_OP 0x4000
static struct { zend_class_entry** ce; uint32_t flags; } dd_op_ce[ZEND_VM_LAST_OPCODE + 1] = {
	[ZEND_NOP] = { .ce = &dd_ce_OpNop },
	[ZEND_EXT_NOP] = { .ce = &dd_ce_OpNop },
	[ZEND_ADD] = { .ce = &dd_ce_OpAdd, .flags = BINARY_OP },
	[ZEND_SUB] = { .ce = &dd_ce_OpSub, .flags = BINARY_OP },
	[ZEND_MUL] = { .ce = &dd_ce_OpMul, .flags = BINARY_OP },
	[ZEND_DIV] = { .ce = &dd_ce_OpDiv, .flags = BINARY_OP },
	[ZEND_MOD] = { .ce = &dd_ce_OpMod, .flags = BINARY_OP },
	[ZEND_SL] = { .ce = &dd_ce_OpSl, .flags = BINARY_OP },
	[ZEND_SR] = { .ce = &dd_ce_OpSr, .flags = BINARY_OP },
	[ZEND_CONCAT] = { .ce = &dd_ce_OpConcat, .flags = BINARY_OP },
	[ZEND_FAST_CONCAT] = { .ce = &dd_ce_OpConcat, .flags = BINARY_OP },
	[ZEND_ROPE_ADD] = { .ce = &dd_ce_OpConcat },
	[ZEND_ROPE_END] = { .ce = &dd_ce_OpConcat },
	[ZEND_BW_OR] = { .ce = &dd_ce_OpBwOr, .flags = BINARY_OP },
	[ZEND_BW_AND] = { .ce = &dd_ce_OpBwAnd, .flags = BINARY_OP },
	[ZEND_BW_XOR] = { .ce = &dd_ce_OpBwXor, .flags = BINARY_OP },
	[ZEND_POW] = { .ce = &dd_ce_OpPow, .flags = BINARY_OP },
	[ZEND_BW_NOT] = { .ce = &dd_ce_OpBwNot, .flags = UNARY_OP },
	[ZEND_BOOL_NOT] = { .ce = &dd_ce_OpBoolNot, .flags = UNARY_OP },
	[ZEND_BOOL_XOR] = { .ce = &dd_ce_OpBoolXor, .flags = BINARY_OP },
	[ZEND_IS_IDENTICAL] = { .ce = &dd_ce_OpIsIdentical, .flags = BINARY_OP },
	[ZEND_IS_NOT_IDENTICAL] = { .ce = &dd_ce_OpIsNotIdentical, .flags = BINARY_OP },
	[ZEND_IS_EQUAL] = { .ce = &dd_ce_OpIsEqual, .flags = BINARY_OP },
	[ZEND_IS_NOT_EQUAL] = { .ce = &dd_ce_OpIsNotEqual, .flags = BINARY_OP },
	[ZEND_IS_SMALLER] = { .ce = &dd_ce_OpIsSmaller, .flags = BINARY_OP },
	[ZEND_IS_SMALLER_OR_EQUAL] = { .ce = &dd_ce_OpIsSmallerOrEqual, .flags = BINARY_OP },
	[ZEND_SPACESHIP] = { .ce = &dd_ce_OpSpaceship, .flags = BINARY_OP },
	[ZEND_CAST] = { .ce = &dd_ce_OpCast, .flags = UNARY_OP },
	[ZEND_BOOL] = { .ce = &dd_ce_OpCast, .flags = UNARY_OP },
	[ZEND_ASSIGN] = { .ce = &dd_ce_OpAssign, .flags = VAR_FIRST | FETCH_FLAG },
	[ZEND_ASSIGN_DIM] = { .ce = &dd_ce_OpAssign, .flags = VAR_FIRST | FETCH_DIM },
	[ZEND_ASSIGN_OBJ] = { .ce = &dd_ce_OpAssign, .flags = VAR_FIRST | FETCH_OBJ },
	[ZEND_ASSIGN_STATIC_PROP] = { .ce = &dd_ce_OpAssign, .flags = VAR_FIRST | FETCH_STATIC_PROP },
	[ZEND_ASSIGN_REF] = { .ce = &dd_ce_OpAssign, .flags = VAR_FIRST | FETCH_FLAG | REF_FLAG },
	[ZEND_ASSIGN_OBJ_REF] = { .ce = &dd_ce_OpAssign, .flags = VAR_FIRST | FETCH_OBJ | REF_FLAG },
	[ZEND_ASSIGN_STATIC_PROP_REF] = { .ce = &dd_ce_OpAssign, .flags = VAR_FIRST | FETCH_STATIC_PROP | REF_FLAG },
	[ZEND_ASSIGN_OP] = { .flags = BINARY_OP | FETCH_FLAG | ASSIGN_OP },
	[ZEND_ASSIGN_DIM_OP] = { .flags = BINARY_OP | FETCH_DIM | ASSIGN_OP },
	[ZEND_ASSIGN_OBJ_OP] = { .flags = BINARY_OP | FETCH_OBJ | ASSIGN_OP },
	[ZEND_ASSIGN_STATIC_PROP_OP] = { .flags = BINARY_OP | FETCH_STATIC_PROP | ASSIGN_OP },
	[ZEND_QM_ASSIGN] = { .ce = &dd_ce_OpQmAssign, .flags = UNARY_OP },
	[ZEND_PRE_INC] = { .ce = &dd_ce_OpPreInc, .flags = VAR_FIRST | FETCH_FLAG },
	[ZEND_PRE_DEC] = { .ce = &dd_ce_OpPreDec, .flags = VAR_FIRST | FETCH_FLAG },
	[ZEND_POST_INC] = { .ce = &dd_ce_OpPostInc, .flags = VAR_FIRST | FETCH_FLAG },
	[ZEND_POST_DEC] = { .ce = &dd_ce_OpPostDec, .flags = VAR_FIRST | FETCH_FLAG },
	[ZEND_PRE_INC_STATIC_PROP] = { .ce = &dd_ce_OpPreInc, .flags = VAR_FIRST | FETCH_STATIC_PROP },
	[ZEND_PRE_DEC_STATIC_PROP] = { .ce = &dd_ce_OpPreDec, .flags = VAR_FIRST | FETCH_STATIC_PROP },
	[ZEND_POST_INC_STATIC_PROP] = { .ce = &dd_ce_OpPostInc, .flags = VAR_FIRST | FETCH_STATIC_PROP },
	[ZEND_POST_DEC_STATIC_PROP] = { .ce = &dd_ce_OpPostDec, .flags = VAR_FIRST | FETCH_STATIC_PROP },
	[ZEND_PRE_INC_OBJ] = { .ce = &dd_ce_OpPreInc, .flags = VAR_FIRST | FETCH_OBJ },
	[ZEND_PRE_DEC_OBJ] = { .ce = &dd_ce_OpPreDec, .flags = VAR_FIRST | FETCH_OBJ },
	[ZEND_POST_INC_OBJ] = { .ce = &dd_ce_OpPostInc, .flags = VAR_FIRST | FETCH_OBJ },
	[ZEND_POST_DEC_OBJ] = { .ce = &dd_ce_OpPostDec, .flags = VAR_FIRST | FETCH_OBJ },
	[ZEND_JMP] = { .ce = &dd_ce_OpJmp },
	[ZEND_JMPZ] = { .ce = &dd_ce_OpJmpz, .flags = CONDITIONAL_JMP },
	[ZEND_JMPNZ] = { .ce = &dd_ce_OpJmpnz, .flags = CONDITIONAL_JMP },
	[ZEND_JMPZ_EX] = { .ce = &dd_ce_OpJmpzEx, .flags = CONDITIONAL_JMP },
	[ZEND_JMPNZ_EX] = { .ce = &dd_ce_OpJmpnzEx, .flags = CONDITIONAL_JMP },
	[ZEND_JMP_SET] = { .ce = &dd_ce_OpJmpSet, .flags = CONDITIONAL_JMP },
	[ZEND_JMP_NULL] = { .ce = &dd_ce_OpJmpNull, .flags = CONDITIONAL_JMP },
	[ZEND_CASE] = { .ce = &dd_ce_OpCase },
	[ZEND_CASE_STRICT] = { .ce = &dd_ce_OpCaseStrict },
	[ZEND_MATCH_ERROR] = { .ce = &dd_ce_OpMatchError },
	[ZEND_CHECK_VAR] = { .ce = &dd_ce_OpCheckVar },
	[ZEND_SEND_VAR_NO_REF_EX] = { .ce = &dd_ce_OpSend, .flags = SEND_OP },
	[ZEND_SEND_VAL] = { .ce = &dd_ce_OpSend, .flags = SEND_OP },
	[ZEND_SEND_VAR_EX] = { .ce = &dd_ce_OpSend, .flags = SEND_OP },
	[ZEND_SEND_REF] = { .ce = &dd_ce_OpSend, .flags = SEND_OP },
	[ZEND_SEND_VAR_NO_REF] = { .ce = &dd_ce_OpSend, .flags = SEND_OP },
	[ZEND_SEND_VAL_EX] = { .ce = &dd_ce_OpSend, .flags = SEND_OP },
	[ZEND_SEND_VAR] = { .ce = &dd_ce_OpSend, .flags = SEND_OP },
	[ZEND_SEND_FUNC_ARG] = { .ce = &dd_ce_OpSend, .flags = SEND_OP },
	[ZEND_SEND_USER] = { .ce = &dd_ce_OpSend, .flags = SEND_OP },
	[ZEND_SEND_UNPACK] = { .ce = &dd_ce_OpSendUnpack, .flags = SEND_OP },
	[ZEND_BEGIN_SILENCE] = { .ce = &dd_ce_OpBeginSilence },
	[ZEND_END_SILENCE] = { .ce = &dd_ce_OpEndSilence },
	[ZEND_INIT_FCALL_BY_NAME] = { .ce = &dd_ce_OpInitFcall, .flags = FCALL_INIT },
	[ZEND_INIT_FCALL] = { .ce = &dd_ce_OpInitFcall, .flags = FCALL_INIT },
	[ZEND_INIT_NS_FCALL_BY_NAME] = { .ce = &dd_ce_OpInitFcall, .flags = FCALL_INIT },
	[ZEND_INIT_DYNAMIC_CALL] = { .ce = &dd_ce_OpInitFcall, .flags = FCALL_INIT },
	[ZEND_FREE] = { .ce = &dd_ce_OpFree, .flags = UNARY_OP },
	[ZEND_INIT_ARRAY] = { .ce = &dd_ce_OpInitArray },
	[ZEND_ADD_ARRAY_ELEMENT] = { .ce = &dd_ce_OpAddArrayElement, .flags = ADD_ARRAY | SKIP_RESULT_REASSIGN },
	[ZEND_INCLUDE_OR_EVAL] = { .ce = &dd_ce_OpIncludeOrEval },
	[ZEND_UNSET_CV] = { .ce = &dd_ce_OpUnset, .flags = VAR_FIRST | FETCH_FLAG },
	[ZEND_UNSET_VAR] = { .ce = &dd_ce_OpUnset, .flags = VAR_FIRST | FETCH_FLAG },
	[ZEND_UNSET_DIM] = { .ce = &dd_ce_OpUnset, .flags = VAR_FIRST | FETCH_DIM },
	[ZEND_UNSET_OBJ] = { .ce = &dd_ce_OpUnset, .flags = VAR_FIRST | FETCH_OBJ },
	[ZEND_UNSET_STATIC_PROP] = { .ce = &dd_ce_OpUnset, .flags = VAR_FIRST | FETCH_STATIC_PROP },
	[ZEND_FE_RESET_R] = { .ce = &dd_ce_OpFeReset },
	[ZEND_FE_RESET_RW] = { .ce = &dd_ce_OpFeReset, .flags = REF_FLAG },
	[ZEND_FE_FETCH_R] = { .ce = &dd_ce_OpFeFetch },
	[ZEND_FE_FETCH_RW] = { .ce = &dd_ce_OpFeFetch, .flags = UNARY_OP },
	[ZEND_FE_FREE] = { .ce = &dd_ce_OpFeFree, .flags = UNARY_OP },
	[ZEND_EXIT] = { .ce = &dd_ce_OpExit, .flags = UNARY_OP },
	[ZEND_FETCH_R] = { .ce = &dd_ce_OpFetchRead, .flags = VAR_FIRST | FETCH_FLAG },
	[ZEND_FETCH_W] = { .ce = &dd_ce_OpFetchWrite, .flags = VAR_FIRST | FETCH_FLAG },
	[ZEND_FETCH_IS] = { .ce = &dd_ce_OpFetchRead, .flags = VAR_FIRST | FETCH_FLAG },
	[ZEND_FETCH_UNSET] = { .ce = &dd_ce_OpFetchWrite, .flags = VAR_FIRST | FETCH_FLAG },
	[ZEND_FETCH_RW] = { .ce = &dd_ce_OpFetchWrite, .flags = VAR_FIRST | FETCH_FLAG },
	[ZEND_FETCH_FUNC_ARG] = { .ce = &dd_ce_OpFetchWrite, .flags = VAR_FIRST | FETCH_FLAG },
	[ZEND_FETCH_DIM_R] = { .ce = &dd_ce_OpFetchRead, .flags = VAR_FIRST | FETCH_DIM },
	[ZEND_FETCH_DIM_W] = { .ce = &dd_ce_OpFetchWrite, .flags = VAR_FIRST | FETCH_DIM },
	[ZEND_FETCH_DIM_IS] = { .ce = &dd_ce_OpFetchRead, .flags = VAR_FIRST | FETCH_DIM },
	[ZEND_FETCH_DIM_UNSET] = { .ce = &dd_ce_OpFetchWrite, .flags = VAR_FIRST | FETCH_DIM },
	[ZEND_FETCH_DIM_RW] = { .ce = &dd_ce_OpFetchWrite, .flags = VAR_FIRST | FETCH_DIM },
	[ZEND_FETCH_DIM_FUNC_ARG] = { .ce = &dd_ce_OpFetchWrite, .flags = VAR_FIRST | FETCH_DIM },
	[ZEND_FETCH_OBJ_R] = { .ce = &dd_ce_OpFetchRead, .flags = VAR_FIRST | FETCH_OBJ },
	[ZEND_FETCH_OBJ_W] = { .ce = &dd_ce_OpFetchWrite, .flags = VAR_FIRST | FETCH_OBJ },
	[ZEND_FETCH_OBJ_IS] = { .ce = &dd_ce_OpFetchRead, .flags = VAR_FIRST | FETCH_OBJ },
	[ZEND_FETCH_OBJ_UNSET] = { .ce = &dd_ce_OpFetchWrite, .flags = VAR_FIRST | FETCH_OBJ },
	[ZEND_FETCH_OBJ_RW] = { .ce = &dd_ce_OpFetchWrite, .flags = VAR_FIRST | FETCH_OBJ },
	[ZEND_FETCH_OBJ_FUNC_ARG] = { .ce = &dd_ce_OpFetchWrite, .flags = VAR_FIRST | FETCH_OBJ },
	[ZEND_FETCH_STATIC_PROP_R] = { .ce = &dd_ce_OpFetchRead, .flags = VAR_FIRST | FETCH_STATIC_PROP },
	[ZEND_FETCH_STATIC_PROP_W] = { .ce = &dd_ce_OpFetchWrite, .flags = VAR_FIRST | FETCH_STATIC_PROP },
	[ZEND_FETCH_STATIC_PROP_IS] = { .ce = &dd_ce_OpFetchRead, .flags = VAR_FIRST | FETCH_STATIC_PROP },
	[ZEND_FETCH_STATIC_PROP_UNSET] = { .ce = &dd_ce_OpFetchWrite, .flags = VAR_FIRST | FETCH_STATIC_PROP },
	[ZEND_FETCH_STATIC_PROP_RW] = { .ce = &dd_ce_OpFetchWrite, .flags = VAR_FIRST | FETCH_STATIC_PROP },
	[ZEND_FETCH_STATIC_PROP_FUNC_ARG] = { .ce = &dd_ce_OpFetchWrite, .flags = VAR_FIRST | FETCH_STATIC_PROP },
	[ZEND_FETCH_LIST_R] = { .ce = &dd_ce_OpFetchList, .flags = VAR_FIRST },
	[ZEND_FETCH_LIST_W] = { .ce = &dd_ce_OpFetchList, .flags = VAR_FIRST | REF_FLAG },
	[ZEND_CATCH] = { .ce = &dd_ce_OpCatch },
	[ZEND_THROW] = { .ce = &dd_ce_OpThrow, .flags = UNARY_OP },
	[ZEND_CLONE] = { .ce = &dd_ce_OpClone, .flags = UNARY_OP },
	[ZEND_RETURN] = { .ce = &dd_ce_OpReturn, .flags = UNARY_OP },
	[ZEND_RETURN_BY_REF] = { .ce = &dd_ce_OpReturn, .flags = UNARY_OP },
	[ZEND_INIT_METHOD_CALL] = { .ce = &dd_ce_OpInitMethodCall },
	[ZEND_INIT_STATIC_METHOD_CALL] = { .ce = &dd_ce_OpInitStaticMethodCall },
	[ZEND_ISSET_ISEMPTY_THIS] = { .flags = FETCH_FLAG | ISSET_ISEMPTY },
	[ZEND_ISSET_ISEMPTY_CV] = { .flags = VAR_FIRST | FETCH_FLAG | ISSET_ISEMPTY },
	[ZEND_ISSET_ISEMPTY_VAR] = { .flags = VAR_FIRST | FETCH_FLAG | ISSET_ISEMPTY },
	[ZEND_ISSET_ISEMPTY_DIM_OBJ] = { .flags = VAR_FIRST | FETCH_DIM | ISSET_ISEMPTY },
	[ZEND_ISSET_ISEMPTY_PROP_OBJ] = { .flags = VAR_FIRST | FETCH_OBJ | ISSET_ISEMPTY },
	[ZEND_ISSET_ISEMPTY_STATIC_PROP] = { .flags = VAR_FIRST | FETCH_STATIC_PROP | ISSET_ISEMPTY },
	[ZEND_INIT_USER_CALL] = { .ce = &dd_ce_OpInitFcall },
	[ZEND_STRLEN] = { .ce = &dd_ce_OpInitFcall },
	[ZEND_DEFINED] = { .ce = &dd_ce_OpInitFcall },
	[ZEND_ECHO] = { .ce = &dd_ce_OpEcho, .flags = UNARY_OP },
	[ZEND_INSTANCEOF] = { .ce = &dd_ce_OpInstanceof },
	[ZEND_MAKE_REF] = { .ce = &dd_ce_OpMakeRef, .flags = UNARY_OP },
	[ZEND_DECLARE_FUNCTION] = { .ce = &dd_ce_OpDeclareFunction },
	[ZEND_DECLARE_LAMBDA_FUNCTION] = { .ce = &dd_ce_OpDeclareFunction },
	[ZEND_DECLARE_CLASS] = { .ce = &dd_ce_OpDeclareClass, .flags = DECLARE_CLASS },
	[ZEND_DECLARE_CLASS_DELAYED] = { .ce = &dd_ce_OpDeclareClass, .flags = DECLARE_CLASS },
	[ZEND_DECLARE_ANON_CLASS] = { .ce = &dd_ce_OpDeclareClass, .flags = DECLARE_CLASS },
	[ZEND_ADD_ARRAY_UNPACK] = { .ce = &dd_ce_OpAddArrayUnpack, .flags = UNARY_OP | ADD_ARRAY | SKIP_RESULT_REASSIGN },
	[ZEND_ASSERT_CHECK] = { .ce = &dd_ce_OpAssertCheck },
	[ZEND_SEPARATE] = { .ce = &dd_ce_OpSeparate, .flags = UNARY_OP },
	[ZEND_FETCH_CLASS_NAME] = { .ce = &dd_ce_OpFetchClassName },
	[ZEND_YIELD] = { .ce = &dd_ce_OpYield },
	[ZEND_YIELD_FROM] = { .ce = &dd_ce_OpYieldFrom, .flags = UNARY_OP },
	[ZEND_FAST_CALL] = { .ce = &dd_ce_OpFastCall },
	[ZEND_FAST_RET] = { .ce = &dd_ce_OpFastRet },
	[ZEND_COPY_TMP] = { .ce = &dd_ce_OpCopyTmp, .flags = UNARY_OP },
	[ZEND_BIND_GLOBAL] = { .ce = &dd_ce_OpBindGlobal },
	[ZEND_COALESCE] = { .ce = &dd_ce_OpCoalesce, .flags = CONDITIONAL_JMP },
	[ZEND_FUNC_NUM_ARGS] = { .ce = &dd_ce_OpInitFcall },
	[ZEND_FUNC_GET_ARGS] = { .ce = &dd_ce_OpInitFcall },
	[ZEND_FETCH_CONSTANT] = { .ce = &dd_ce_OpFetchConstant },
	[ZEND_FETCH_CLASS_CONSTANT] = { .ce = &dd_ce_OpFetchClassConstant },
	[ZEND_BIND_LEXICAL] = { .ce = &dd_ce_OpBindLexical },
	[ZEND_BIND_STATIC] = { .ce = &dd_ce_OpBindStatic },
	[ZEND_IN_ARRAY] = { .ce = &dd_ce_OpInitFcall },
	[ZEND_COUNT] = { .ce = &dd_ce_OpInitFcall },
	[ZEND_GET_CLASS] = { .ce = &dd_ce_OpInitFcall },
	[ZEND_GET_CALLED_CLASS] = { .ce = &dd_ce_OpInitFcall },
	[ZEND_GET_TYPE] = { .ce = &dd_ce_OpInitFcall },
	[ZEND_ARRAY_KEY_EXISTS] = { .ce = &dd_ce_OpInitFcall },
	[ZEND_CALLABLE_CONVERT] = { .ce = &dd_ce_OpCallableConvert },
	[ZEND_NEW] = { .ce = &dd_ce_OpNew },
};

static inline zend_object *const_node_str(zend_string *str) {
	zend_object *c = zend_objects_new(dd_ce_ConstNode);
	zval zv;
	ZVAL_STR(&zv, str);
	zend_update_property(dd_ce_ConstNode, c, ZEND_STRL("value"), &zv);
	return c;
}

static inline zend_object *const_node_long(zend_long lval) {
	zend_object *c = zend_objects_new(dd_ce_ConstNode);
	zval zv;
	ZVAL_LONG(&zv, lval);
	zend_update_property(dd_ce_ConstNode, c, ZEND_STRL("value"), &zv);
	return c;
}

static inline zend_object *dd_node(znode_op node, zend_uchar op_type, zend_object **vars, zend_op *op, int this_var) {
	if (op_type & (IS_VAR | IS_TMP_VAR | IS_CV)) {
		zend_object *ref = vars[EX_VAR_TO_NUM(node.var)];
		GC_ADDREF(ref);
		return ref;
	}
	if (op_type & IS_CONST) {
		zval *literal = RT_CONSTANT(op, node);
		zend_object *c = zend_objects_new(dd_ce_ConstNode);
		if (Z_OPT_TYPE_P(literal) == IS_CONSTANT_AST) {
			zend_update_property_obj(dd_ce_ConstNode, c, ZEND_STRL("value"), dd_convert_ast(Z_ASTVAL_P(literal)));
		} else {
			Z_TRY_ADDREF_P(literal);
			zend_update_property(dd_ce_ConstNode, c, ZEND_STRL("value"), literal);
		}
		return c;
	}
	if ((zend_get_opcode_flags(op->opcode) & ZEND_VM_OP_MASK) == ZEND_VM_OP_THIS) {
		zend_object *ref = vars[this_var];
		GC_ADDREF(ref);
		return ref;
	}
	zend_object *unused = zend_objects_new(dd_ce_UnusedNode);
	zend_update_property_long(dd_ce_UnusedNode, unused, ZEND_STRL("value"), node.num);
	return unused;
}

static inline zend_object *dd_node_class(znode_op op) {
	zend_string *val;
	int fetch_type = op.num & ZEND_FETCH_CLASS_MASK;
	if (fetch_type == ZEND_FETCH_CLASS_STATIC) {
		val = zend_string_init(ZEND_STRL("static"), 0);
	} else if (fetch_type == ZEND_FETCH_CLASS_SELF) {
		val = zend_string_init(ZEND_STRL("self"), 0);
	} else if (fetch_type == ZEND_FETCH_CLASS_PARENT) {
		val = zend_string_init(ZEND_STRL("parent"), 0);
	}
	return const_node_str(val);
}

zend_object *convert_op_array(zend_op_array *op_array) {
	const int args_per_op = 6;

	zend_object *op_array_obj = zend_objects_new(dd_ce_OpArray);
	zend_array *func_args = zend_new_array(op_array->num_args);
	zend_array *op_array_flags = zend_new_array(0);

	zend_object **objs = ecalloc(op_array->last * args_per_op, sizeof(zend_object *));
	int globals_var = op_array->last_var + op_array->T;
	int this_var = op_array->last_var + op_array->T + 1;
	bool have_this_var = (op_array->fn_flags & ZEND_ACC_USES_THIS) != 0;
	zend_object **vars = ecalloc(op_array->last_var + op_array->T + 1 + have_this_var, sizeof(zend_object *));
	zend_array *vars_array = zend_new_array(op_array->last_var + op_array->T + 1 + have_this_var);
	for (int i = 0; i < op_array->last_var; ++i) {
		vars[i] = zend_objects_new(dd_ce_NamedVarNode);
		zend_update_property_str(dd_ce_NamedVarNode, vars[i], ZEND_STRL("var"), zend_string_copy(op_array->vars[i]));
		zval zv;
		ZVAL_OBJ(&zv, vars[i]);
		zend_hash_add_new(vars_array, op_array->vars[i], &zv);
	}
	vars[globals_var] = zend_objects_new(dd_ce_NamedVarNode);
	zend_update_property_str(dd_ce_NamedVarNode, vars[globals_var], ZEND_STRL("var"), zend_string_init(ZEND_STRL("GLOBALS"), 0));
	if (have_this_var) {
		vars[this_var] = zend_objects_new(dd_ce_NamedVarNode);
		zend_update_property_str(dd_ce_NamedVarNode, vars[this_var], ZEND_STRL("var"), ZSTR_KNOWN(ZEND_STR_THIS));
		zval zv;
		ZVAL_OBJ(&zv, vars[this_var]);
		zend_hash_add_new(vars_array, ZSTR_KNOWN(ZEND_STR_THIS), &zv);
	}

	zend_op *last_fetch_class;
	for (int i = 0; i < op_array->last; ++i) {
		int obj_offset = i * args_per_op;
#define EMIT(opce) do { obj = objs[obj_offset++] = zend_objects_new(opce); if (!(flags & SKIP_RESULT_REASSIGN) && (op->result_type & (IS_VAR | IS_TMP_VAR))) { vars[EX_VAR_TO_NUM(op->result.var)] = obj; } PROP("lineno", op->lineno, _long); } while(0)
#define NODE_REF(node) dd_node(node, node ## _type, vars, op, this_var)
#define PROP_NODE(name, node) PROP(name, NODE_REF(op->node), _obj)
#define PROP_NODE_CLASS(name, node) \
	if (op->result_type == IS_CONST) { \
		PROP_NODE(name, node); \
	} else if (op->result_type == IS_VAR) { \
		zend_op *cur_op = op; \
		op = last_fetch_class; \
		PROP_NODE(name, op1); \
		op = cur_op; \
	} else if (op->result_type == IS_UNUSED) { \
		PROP(name, dd_node_class(op->node), _obj); \
	}

		zend_op *ops = op_array->opcodes, *op = &ops[i];
		zend_object *obj;
		uint32_t flags = dd_op_ce[op->opcode].flags;
		if (dd_op_ce[op->opcode].ce) {
			EMIT(*dd_op_ce[op->opcode].ce);
		}
		if (flags & ASSIGN_OP) {
			EMIT(*dd_op_ce[op->extended_value].ce);
		}
		if (flags & ISSET_ISEMPTY) {
			EMIT((op->extended_value & ZEND_ISEMPTY) ? dd_ce_OpEmpty : dd_ce_OpIsset);
		}
		if (flags & UNARY_OP) {
			PROP_NODE("arg", op1);
		} else if (flags & BINARY_OP) {
			PROP_NODE("arg1", op1);
			PROP_NODE("arg2", op2);
		}
		if (flags & FETCH_FLAG) {
			uint32_t fetch_flags = flags & (FETCH_DIM | FETCH_OBJ | FETCH_STATIC_PROP);
			zend_object *fetch_mode;
			switch (fetch_flags) {
				case FETCH_DIM:
					fetch_mode = zend_objects_new(dd_ce_FetchTargetDim);
					zend_update_property_obj(fetch_mode->ce, fetch_mode, ZEND_STRL("dim"), NODE_REF(op->op2));
					break;
				case FETCH_OBJ:
					fetch_mode = zend_objects_new(dd_ce_FetchTargetObj);
					zend_update_property_obj(fetch_mode->ce, fetch_mode, ZEND_STRL("prop"), NODE_REF(op->op2));
					break;
				case FETCH_STATIC_PROP:
					fetch_mode = zend_objects_new(dd_ce_FetchTargetStaticProp);
					zend_update_property_obj(fetch_mode->ce, fetch_mode, ZEND_STRL("prop"), NODE_REF(op->op2));
					break;
				default:
					fetch_mode = zend_objects_new(dd_ce_FetchTarget);
					break;
			}
			if (flags & BINARY_OP) {
				PROP("assign", fetch_mode, _obj);
			} else {
				PROP("target", fetch_mode, _obj);
			}
		}
		if (flags & VAR_FIRST) {
			PROP_NODE("var", op1);
		}
		if (flags & SEND_OP) {
			PROP_NODE("value", op1);
		}
		if (flags & FCALL_INIT) {
			if (op->opcode == ZEND_INIT_DYNAMIC_CALL || op->opcode == ZEND_INIT_NS_FCALL_BY_NAME) {
				PROP_NODE("function", op2);
			} else {
				zend_string *function_name = Z_STR_P(RT_CONSTANT(op, op->op1));
				PROP("function", const_node_str(zend_strpprintf(0, "\\%.*s", (int) ZSTR_LEN(function_name), ZSTR_VAL(function_name))), _obj);
			}
		}
		if (flags & REF_FLAG) {
			PROP("byRef", true, _bool);
		}
		if (flags & DECLARE_CLASS) {
			PROP("name", zend_string_copy(Z_STR_P(RT_CONSTANT(op, op->op1))), _str);
		}
		if (flags & ADD_ARRAY) {
			GC_ADDREF(vars[op->result.var]);
			PROP("array", vars[op->result.var], _obj);
		}
		switch (op->opcode) {
			case ZEND_RECV_INIT:
			case ZEND_RECV_VARIADIC:
			case ZEND_RECV:
				obj = zend_objects_new(dd_ce_FunctionArgument);
				PROP_NODE("variable", result);
				if (op->opcode == ZEND_RECV_INIT) {
					zval *init = RT_CONSTANT(op, op->op1);
					zend_object *ast_obj;
					if (Z_OPT_TYPE_P(init) == IS_CONSTANT_AST) {
						ast_obj = dd_convert_ast(Z_ASTVAL_P(init));
					} else {
						ast_obj = zend_objects_new(dd_ce_AstZval);
						Z_TRY_ADDREF_P(init);
						zend_update_property(ast_obj->ce, ast_obj, ZEND_STRL("value"), init);
					}
					PROP("default", ast_obj, _obj);
				} else if (op->opcode == ZEND_RECV_VARIADIC) {
					PROP("variadic", true, _bool);
				}
				if (ARG_SHOULD_BE_SENT_BY_REF((zend_function *)op_array, op->op1.num)) {
					PROP("byRef", true, _bool);
				}
				zval funcargzv;
				ZVAL_OBJ(&funcargzv, obj);
				zend_hash_next_index_insert_new(func_args, &funcargzv);
				break;

			case ZEND_EXT_NOP:
				PROP("ext", true, _bool);
				break;

			case ZEND_BOOL:;
				const char *cast_type = "Bool";
			case ZEND_CAST:
				switch (op->extended_value) {
					case IS_LONG:
						cast_type = "Long";
						break;
					case IS_DOUBLE:
						cast_type = "Double";
						break;
					case IS_STRING:
						cast_type = "String";
						break;
					case IS_ARRAY:
						cast_type = "Array";
						break;
					case IS_OBJECT:
						cast_type = "Object";
						break;
				}
				zend_object *cast_case = zend_enum_get_case_cstr(dd_ce_CastType, cast_type);
				GC_ADDREF(cast_case);
				PROP("type", cast_case, _obj);
				break;

			case ZEND_FETCH_LIST_R:
			case ZEND_FETCH_LIST_W:
				PROP_NODE("dim", op2);
				break;

			case ZEND_CASE:
			case ZEND_CASE_STRICT:
				PROP_NODE("switch", op1);
				PROP_NODE("compare", op2);
				break;

			case ZEND_MATCH_ERROR:
				PROP_NODE("switch", op1);
				break;

			case ZEND_ROPE_INIT:;
				zend_object *rope_start = NODE_REF(op->op2);
				vars[EX_VAR_TO_NUM(op->result.var)] = rope_start;
				break;

			case ZEND_ROPE_ADD:
			case ZEND_ROPE_END:
				PROP_NODE("arg1", op1);
				PROP_NODE("arg2", op2);
				break;

			case ZEND_INIT_ARRAY:
				if (op->op1_type == IS_UNUSED) {
					break;
				}
			case ZEND_ADD_ARRAY_ELEMENT:
				PROP_NODE("value", op1);
				if (op->op2_type != IS_UNUSED) {
					PROP_NODE("key", op2);
				}
				break;

			case ZEND_INCLUDE_OR_EVAL:
				PROP_NODE("arg", op1);
				const char *include_mode;
				switch (op->extended_value) {
					case ZEND_INCLUDE_ONCE: include_mode = "IncludeOnce"; break;
					case ZEND_REQUIRE_ONCE: include_mode = "RequireOnce"; break;
					case ZEND_INCLUDE: include_mode = "Include"; break;
					case ZEND_REQUIRE: include_mode = "Require"; break;
					case ZEND_EVAL: include_mode = "Eval"; break;
				}
				zend_object *include_case = zend_enum_get_case_cstr(dd_ce_IncludeMode, include_mode);
				GC_ADDREF(include_case);
				PROP("mode", include_case, _obj);
				break;

			case ZEND_FE_RESET_R:
			case ZEND_FE_RESET_RW:
				PROP_NODE("arg", op1);
				break;

			case ZEND_CATCH:
				PROP_NODE("var", result);
				PROP("class", zval_get_string(RT_CONSTANT(op, op->op1)), _str);
				break;

			case ZEND_INIT_METHOD_CALL:
				PROP_NODE("object", op1);
				PROP_NODE("function", op2);
				break;

			case ZEND_INIT_STATIC_METHOD_CALL:
				PROP_NODE_CLASS("class", op1);
				PROP_NODE("function", op2);
				break;

			case ZEND_INIT_USER_CALL:
				PROP_NODE("function", op1);
				EMIT(dd_ce_OpSend);
				PROP_NODE("value", op2);
				break;

			case ZEND_NEW:
				PROP_NODE_CLASS("class", op1);
				break;

			case ZEND_SEND_ARRAY:
				if (op->op2_type == IS_UNUSED) {
					EMIT(dd_ce_OpSend);
					PROP_NODE("value", op1);
				} else {
					EMIT(dd_ce_OpInitFcall);
					PROP("function", const_node_str(zend_string_init(ZEND_STRL("\\array_slice"), 0)), _obj);
					EMIT(dd_ce_OpSend);
					PROP_NODE("value", op1);
					EMIT(dd_ce_OpSend);
					PROP("value", const_node_long(op->extended_value), _obj);
					EMIT(dd_ce_OpSend);
					PROP_NODE("value", op2);
					EMIT(dd_ce_OpDoFcall);
					zend_object *fake_arg = obj;
					EMIT(dd_ce_OpSend);
					PROP("value", fake_arg, _obj);
				}
				break;

			case ZEND_STRLEN:
				PROP("function", const_node_str(zend_string_init(ZEND_STRL("\\strlen"), 0)), _obj);
				EMIT(dd_ce_OpSend);
				PROP_NODE("value", op1);
				break;

			case ZEND_FUNC_NUM_ARGS:
				PROP("function", const_node_str(zend_string_init(ZEND_STRL("\\func_num_args"), 0)), _obj);
				break;

			case ZEND_FUNC_GET_ARGS:
				PROP("function", const_node_str(zend_string_init(ZEND_STRL("\\func_get_args"), 0)), _obj);
				if (op->op1_type != IS_UNUSED) {
					EMIT(dd_ce_OpSend);
					PROP_NODE("value", op1);
				}
				break;

			case ZEND_DEFINED:
				PROP("function", const_node_str(zend_string_init(ZEND_STRL("\\defined"), 0)), _obj);
				EMIT(dd_ce_OpSend);
				PROP_NODE("value", op1);
				EMIT(dd_ce_OpDoFcall);
				break;

			case ZEND_TYPE_CHECK:
				if (op->extended_value == IS_TRUE || op->extended_value == IS_FALSE || op->extended_value == IS_NULL) {
					EMIT(dd_ce_OpIsIdentical);
					PROP_NODE("arg1", op1);
					zend_object *c = zend_objects_new(dd_ce_ConstNode);
					zval zv;
					Z_TYPE_INFO(zv) = op->extended_value;
					zend_update_property(dd_ce_ConstNode, c, ZEND_STRL("value"), &zv);
					PROP("arg2", c, _obj);
					break;
				}
				EMIT(dd_ce_OpInitFcall);
				zend_string *is_func;
				switch (op->extended_value) {
					case _IS_BOOL: is_func = zend_string_init(ZEND_STRL("\\is_bool"), 0); break;
					case IS_LONG: is_func = zend_string_init(ZEND_STRL("\\is_int"), 0); break;
					case IS_DOUBLE: is_func = zend_string_init(ZEND_STRL("\\is_float"), 0); break;
					case IS_STRING: is_func = zend_string_init(ZEND_STRL("\\is_string"), 0); break;
					case IS_ARRAY: is_func = zend_string_init(ZEND_STRL("\\is_array"), 0); break;
					case IS_OBJECT: is_func = zend_string_init(ZEND_STRL("\\is_object"), 0); break;
					case IS_RESOURCE: is_func = zend_string_init(ZEND_STRL("\\is_resource"), 0); break;
				}
				PROP("function", const_node_str(is_func), _obj);
				EMIT(dd_ce_OpSend);
				PROP_NODE("value", op2);
				break;

			case ZEND_FETCH_CLASS:
				last_fetch_class = op;
				break;

			case ZEND_INSTANCEOF:
				PROP_NODE("object", op1);
				PROP_NODE_CLASS("class", op2);
				break;

			case ZEND_DECLARE_FUNCTION:
				PROP_NODE("name", op1);
				break;

			case ZEND_DECLARE_LAMBDA_FUNCTION:
				// Hmm...? Maybe just return an id?
				PROP("name", zend_string_copy(op_array->dynamic_func_defs[op->op2.num]->function_name), _str);
				break;

			case ZEND_FETCH_CLASS_NAME:
				PROP_NODE_CLASS("arg", op1);
				break;

			case ZEND_FETCH_CONSTANT:;
				zend_string *constant_name = Z_STR_P(RT_CONSTANT(op, op->op2));
				if (op->op1.num == IS_CONSTANT_UNQUALIFIED_IN_NAMESPACE) {
					constant_name = zend_string_copy(constant_name);
				} else {
					constant_name = zend_strpprintf(0, "\\%.*s", (int) ZSTR_LEN(constant_name), ZSTR_VAL(constant_name));
				}
				PROP("name", constant_name, _str);
				break;

			case ZEND_FETCH_CLASS_CONSTANT:
				PROP_NODE_CLASS("arg", op1);
				PROP("name", zend_string_copy(Z_STR_P(RT_CONSTANT(op, op->op2))), _str);
				break;

			case ZEND_YIELD:
				if (op->op1_type != IS_UNUSED) {
					PROP_NODE("arg", op1);
				}
				if (op->op2_type != IS_UNUSED) {
					PROP_NODE("key", op2);
				}
				break;

			case ZEND_BIND_GLOBAL:
				PROP_NODE("arg", op1);
				PROP_NODE("default", op2);
				break;

			case ZEND_BIND_LEXICAL:
				PROP_NODE("closure", op1);
				PROP_NODE("var", op2);
				if (op->extended_value & ZEND_BIND_REF) {
					PROP("byRef", true, _bool);
				}
				break;

			case ZEND_BIND_STATIC:
				PROP_NODE("var", op1);
				if (op->extended_value & ZEND_BIND_REF) {
					PROP("byRef", true, _bool);
				}
				break;

			case ZEND_ISSET_ISEMPTY_THIS:
				GC_ADDREF(vars[this_var]);
				PROP("var", vars[this_var], _obj);
				break;

			case ZEND_FETCH_THIS:
				vars[EX_VAR_TO_NUM(op->result.var)] = vars[this_var];
				break;

			case ZEND_FETCH_GLOBALS:
				vars[EX_VAR_TO_NUM(op->result.var)] = vars[globals_var];
				break;

			case ZEND_IN_ARRAY:
				PROP("function", const_node_str(zend_string_init(ZEND_STRL("\\in_array"), 0)), _obj);
				EMIT(dd_ce_OpSend);
				PROP_NODE("value", op1);
				EMIT(dd_ce_OpSend);
				PROP_NODE("value", op2);
				break;

			case ZEND_COUNT:
				PROP("function", const_node_str(zend_string_init(ZEND_STRL("\\count"), 0)), _obj);
				EMIT(dd_ce_OpSend);
				PROP_NODE("value", op1);
				break;

			case ZEND_GET_CLASS:
				PROP("function", const_node_str(zend_string_init(ZEND_STRL("\\get_class"), 0)), _obj);
				EMIT(dd_ce_OpSend);
				PROP_NODE("value", op1);
				break;

			case ZEND_GET_CALLED_CLASS:
				PROP("function", const_node_str(zend_string_init(ZEND_STRL("\\get_called_class"), 0)), _obj);
				break;

			case ZEND_GET_TYPE:
				PROP("function", const_node_str(zend_string_init(ZEND_STRL("\\get_type"), 0)), _obj);
				EMIT(dd_ce_OpSend);
				PROP_NODE("value", op1);
				break;

			case ZEND_ARRAY_KEY_EXISTS:
				PROP("function", const_node_str(zend_string_init(ZEND_STRL("\\strlen"), 0)), _obj);
				EMIT(dd_ce_OpSend);
				PROP_NODE("value", op1);
				EMIT(dd_ce_OpSend);
				PROP_NODE("value", op2);
				break;
		}
	}

	int objs_count = 0;

	// pass two, resolve refs to non-forward ops
	for (int i = 0; i < op_array->last; ++i) {
		zend_op *ops = op_array->opcodes, *op = &ops[i];
		uint32_t flags = dd_op_ce[op->opcode].flags;
		zend_object *obj = objs[i * args_per_op];

		objs_count += obj != NULL;

		if (flags & CONDITIONAL_JMP) {
			zend_object *target = objs[OP_JMP_ADDR(&op_array->opcodes[i], op_array->opcodes[i].op2) - op_array->opcodes];
			GC_ADDREF(target);
			zend_object *refNode = zend_objects_new(dd_ce_OpRefNode);
			zend_update_property_obj(refNode->ce, refNode, ZEND_STRL("target"), target);
			PROP("target", refNode, _obj);
			continue;
		}

		switch (op->opcode) {
			case ZEND_ASSERT_CHECK:
			case ZEND_FAST_CALL:
			case ZEND_FAST_RET:
			case ZEND_JMP:;
				zend_object *target = objs[OP_JMP_ADDR(&op_array->opcodes[i], op_array->opcodes[i].op1) - op_array->opcodes];
				GC_ADDREF(target);
				zend_object *refNode = zend_objects_new(dd_ce_OpRefNode);
				zend_update_property_obj(refNode->ce, refNode, ZEND_STRL("target"), target);
				PROP("target", refNode, _obj);
				break;
			case ZEND_CATCH:;
				zend_object *next_catch = objs[OP_JMP_ADDR(&op_array->opcodes[i], op_array->opcodes[i].op2) - op_array->opcodes];
				GC_ADDREF(next_catch);
				PROP("after_catch", next_catch, _obj);
				break;
		}
	}

	// last pass, fill oobjects in array
	zend_array *ops_array = zend_new_array(objs_count + op_array->last_try_catch);

	zend_object **try_objs = emalloc(op_array->last_try_catch * sizeof(zend_object *));

	for (int i = 0, try = 0; i < op_array->last; ++i) {
		while (try < op_array->last_try_catch && i == op_array->try_catch_array[try].try_op) {
			zval zv;
			zend_object *try_obj = try_objs[try++] = zend_objects_new(dd_ce_OpTry);
			ZVAL_OBJ(&zv, try_obj);
			zend_hash_next_index_insert_new(ops_array, &zv);
		}
		if (objs[i]) {
			if (op_array->opcodes[i].opcode == ZEND_CATCH) {
				for (int j = try - 1; j >= 0; --j) {
					if (op_array->try_catch_array[j].catch_op <= i) {
						zend_object *obj = try_objs[i];
						GC_ADDREF(obj);
						PROP("try", obj, _obj);
						break;
					}
				}
			}

			for (int j = 0; j < args_per_op; ++j) {
				if (objs[i * args_per_op + j]) {
					zval zv;
					ZVAL_OBJ(&zv, objs[i * args_per_op + j]);
					zend_hash_next_index_insert_new(ops_array, &zv);
				} else {
					break;
				}
			}
		}
	}


	OBJ_RELEASE(vars[globals_var]);
	efree(vars);
	efree(try_objs);

#define ADD_FLAG(name) do { \
		zval zv; \
		ZVAL_OBJ_COPY(&zv, zend_enum_get_case_cstr(dd_ce_OpArrayFlags, name)); \
		zend_hash_next_index_insert_new(op_array_flags, &zv); \
	} while (0)

	if (op_array->fn_flags & ZEND_ACC_GENERATOR) {
		ADD_FLAG("Generator");
	}
	if (op_array->fn_flags & ZEND_ACC_RETURN_REFERENCE) {
		ADD_FLAG("ReturnByRef");
	}

	zval prop_zv;
	ZVAL_ARR(&prop_zv, ops_array);
	zend_update_property(op_array_obj->ce, op_array_obj, ZEND_STRL("ops"), &prop_zv);
	ZVAL_ARR(&prop_zv, vars_array);
	zend_update_property(op_array_obj->ce, op_array_obj, ZEND_STRL("vars"), &prop_zv);
	ZVAL_ARR(&prop_zv, func_args);
	zend_update_property(op_array_obj->ce, op_array_obj, ZEND_STRL("args"), &prop_zv);
	ZVAL_ARR(&prop_zv, op_array_flags);
	zend_update_property(op_array_obj->ce, op_array_obj, ZEND_STRL("flags"), &prop_zv);

	return op_array_obj;

#undef EMIT
#undef PROP_NODE
#undef OBJ_PROP
}


static bool this_guaranteed_exists(zend_op_array *op_array) {
	/* Instance methods always have a $this.
	 * This also includes closures that have a scope and use $this. */
	return op_array->scope != NULL && (op_array->fn_flags & ZEND_ACC_STATIC) == 0;
}

static inline zend_object *dd_null_or_obj(zval *zv) {
	if (Z_TYPE_P(zv) != IS_OBJECT) {
		return NULL;
	}
	return Z_OBJ_P(zv);
}
#define BOOL_PROP(name) zend_is_true(zend_read_property(obj->ce, obj, ZEND_STRL(name), true, &rv))
// TODO do a pre-pass whether all props are initialized
#define STR_PROP(name) Z_STR_P(zend_read_property(obj->ce, obj, ZEND_STRL(name), true, &rv))
#define OBJ_PROP(name) dd_null_or_obj(zend_read_property(obj->ce, obj, ZEND_STRL(name), true, &rv))

static inline bool set_node(zend_object *obj, zend_op_array *op_array, zend_op **op, znode_op *node, zend_uchar *type, HashTable *tmps, HashTable *cvs, zval **literals, uint32_t *literal_count, uint32_t *T, char **err) {
	if (obj->ce == dd_ce_ConstNode) {
		if ((*literal_count & (*literal_count - 1)) == 0 && *literal_count >= 32) {
			*literals = erealloc(*literals, *literal_count * sizeof(zval *));
		}
		*type = IS_CONST;
		zval rv, *val = zend_read_property(obj->ce, obj, ZEND_STRL("var"), true, &rv);
		ZVAL_DEREF(val);
		if (Z_TYPE_P(val) == IS_UNDEF || Z_TYPE_P(val) == IS_OBJECT) {
			*err = "";
			return false;
		}
		ZVAL_COPY(&(*literals)[(*literal_count)++], val);
		return true;
	}
	if (obj->ce == dd_ce_NamedVarNode) {
		*type = IS_CV;
		zval rv, *cv, *nameprop = zend_read_property(obj->ce, obj, ZEND_STRL("var"), true, &rv);
		if (Z_TYPE_P(nameprop) != IS_STRING) {
			*err = "Variable name is not a string.";
			return false;
		}
		if (!(cv = zend_hash_find(cvs, Z_STR_P(nameprop)))) {
			if (zend_string_equals_literal(Z_STR_P(nameprop), "this")) {
				if (this_guaranteed_exists(op_array) && (zend_get_opcode_flags((*op)->opcode) & ZEND_VM_OP_MASK) == ZEND_VM_OP_THIS) {
					*type = IS_UNUSED;
				} else {
					*(*op + 1) = **op;
					uint32_t tmp = (*T)++;
					*(*op++) = (zend_op){
						.opcode = ZEND_FETCH_THIS,
						.op1_type = IS_UNUSED,
						.op2_type = IS_UNUSED,
						.result_type = IS_VAR,
						.result = { tmp }
					};
					*type = IS_VAR;
					node->var = tmp;
					zval_ptr_dtor(&rv);
					return true;
				}
			} else {
				zval zv;
				ZVAL_LONG(&zv, zend_hash_num_elements(cvs));
				cv = zend_hash_add_new(cvs, Z_STR_P(nameprop), &zv);
			}
		}
		node->var = Z_LVAL_P(cv);
		zval_ptr_dtor(&rv);
		return true;
	}
	if (instanceof_function(obj->ce, dd_ce_StackNode)) {
		if (instanceof_function(obj->ce, dd_ce_TmpNode)) {
			*type = IS_TMP_VAR;
		} else {
			*type = IS_VAR;
		}
		zval *zv = zend_hash_index_find(tmps, (zend_ulong)obj);
		if (!zv) {
			*err = "Referenced opcodes must be used exactly once after their definition.";
			return false;
		}
		zend_hash_index_del(tmps, (zend_ulong)obj);
		node->var = Z_LVAL_P(zv);
		return true;
	}
	return false;
}

static inline bool is_this_var(zend_object *obj) {
	zval rv;
	return obj->ce == dd_ce_NamedVarNode && zend_string_equals_literal(STR_PROP("var"), "this");
}

void adjust_fetch_type(zend_object *var, int fetch_type) {
	// TODO
}

void convert_to_ops(zend_array *array, char **err) {
	zend_op_array *op_array; // TODO
	zend_op *ops = ecalloc(0, 0); // TODO
	zend_op *op = ops - 1;
	zval *objzvp;
	zval rv;

	uint32_t T = 0;
	HashTable cvs;
	HashTable tmps;
	HashTable jumps;
	zend_hash_init(&cvs, 8, hashfun, ZVAL_PTR_DTOR, 0);
	zend_hash_init(&tmps, 8, hashfun, NULL, 0);
	zend_hash_init(&jumps, 8, hashfun, NULL, 0);
	zval *literals = emalloc(sizeof(zval) * 16);
	uint32_t literal_count = 0;

	ZEND_HASH_FOREACH_VAL(array, objzvp) {
#define EMIT(code) do { (++op)->opcode = (code); op->op1_type = IS_UNUSED; op->op2_type = IS_UNUSED; op->result_type = result_type; if (result_type & (IS_VAR | IS_TMP_VAR)) { zval zv; ZVAL_LONG(&zv, T++); zend_hash_index_add(&tmps, (zend_ulong)obj, &zv); } zval num; ZVAL_LONG(&num, op - ops); zend_hash_index_add(&jumps, obj->handle, &num); result_type = IS_UNUSED; } while(0)
#define ASSIGN_NODE(obj, node) do { if (!set_node(obj, op_array, &op, &op->op ## node, &op->op ## node ## _type, &tmps, &cvs, &literals, &literal_count, &T, err)) { zval_ptr_dtor(&rv); goto err; } zval_ptr_dtor(&rv); } while(0)
#define PROP_NODE(name, node) ASSIGN_NODE(OBJ_PROP(name), node)

		if (Z_TYPE_P(objzvp) != IS_OBJECT) {
			*err = "";
			goto err;
		}
		zend_object *obj = Z_OBJ_P(objzvp);

		int result_type = IS_UNUSED;
		if (instanceof_function(obj->ce, dd_ce_TmpNode)) {
			result_type = IS_TMP_VAR;
		} else if (instanceof_function(obj->ce, dd_ce_UnnamedVarNode)) {
			result_type = IS_VAR;
		} else if (instanceof_function(obj->ce, dd_ce_NamedVarNode)) {
			result_type = IS_CV;
		}

		if (obj->ce == dd_ce_OpNop) {
			EMIT(BOOL_PROP("ext") ? ZEND_EXT_NOP : ZEND_NOP);
		} else if (obj->ce == dd_ce_OpAdd) {
			EMIT(ZEND_ADD);
binary_op:
			PROP_NODE("arg1", 1);
			PROP_NODE("arg2", 2);
			zend_object *fetch = OBJ_PROP("fetch");
			if (fetch != NULL) {
				op->extended_value = op->opcode;
				if (fetch->ce == dd_ce_FetchTarget) {
					op->opcode = ZEND_ASSIGN_OP;
				} else {
					zend_uchar *opcode = &op->opcode;
					EMIT(ZEND_OP_DATA);
					obj = fetch;
					if (fetch->ce == dd_ce_FetchTargetDim) {
						*opcode = ZEND_ASSIGN_DIM_OP;
						PROP_NODE("dim", 1);
					} else if (fetch->ce == dd_ce_FetchTargetObj) {
						*opcode = ZEND_ASSIGN_OBJ_OP;
						PROP_NODE("prop", 1);
					} else if (fetch->ce == dd_ce_FetchTargetStaticProp) {
						*opcode = ZEND_ASSIGN_STATIC_PROP_OP;
						PROP_NODE("prop", 1);
					}
				}
			}
		} else if (obj->ce == dd_ce_OpSub) {
			EMIT(ZEND_SUB);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpMul) {
			EMIT(ZEND_MUL);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpDiv) {
			EMIT(ZEND_DIV);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpMod) {
			EMIT(ZEND_MOD);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpSl) {
			EMIT(ZEND_SL);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpSr) {
			EMIT(ZEND_SR);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpConcat) {
			EMIT(ZEND_CONCAT);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpBwOr) {
			EMIT(ZEND_BW_OR);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpBwAnd) {
			EMIT(ZEND_BW_AND);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpBwXor) {
			EMIT(ZEND_BW_XOR);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpPow) {
			EMIT(ZEND_POW);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpBoolXor) {
			EMIT(ZEND_BOOL_XOR);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpIsIdentical) {
			EMIT(ZEND_IS_IDENTICAL);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpIsNotIdentical) {
			EMIT(ZEND_IS_NOT_IDENTICAL);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpIsEqual) {
			EMIT(ZEND_IS_EQUAL);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpIsNotEqual) {
			EMIT(ZEND_IS_NOT_EQUAL);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpIsSmaller) {
			EMIT(ZEND_IS_SMALLER);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpIsSmallerOrEqual) {
			EMIT(ZEND_IS_SMALLER_OR_EQUAL);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpSpaceship) {
			EMIT(ZEND_SPACESHIP);
			goto binary_op;
		} else if (obj->ce == dd_ce_OpBoolNot) {
			EMIT(ZEND_BOOL_NOT);
unary_op:
			PROP_NODE("arg", 1);
		} else if (obj->ce == dd_ce_OpBwNot) {
			EMIT(ZEND_BW_NOT);
			goto unary_op;
		} else if (obj->ce == dd_ce_OpCast) {
			zend_string *name = Z_STR_P(zend_enum_fetch_case_name(OBJ_PROP("type")));
			if (zend_string_equals_literal(name, "Bool")) {
				EMIT(ZEND_BOOL);
			} else {
				EMIT(ZEND_CAST);
				if (zend_string_equals_literal(name, "Long")) {
					op->extended_value = IS_LONG;
				} else if (zend_string_equals_literal(name, "Double")) {
					op->extended_value = IS_DOUBLE;
				} else if (zend_string_equals_literal(name, "String")) {
					op->extended_value = IS_STRING;
				} else if (zend_string_equals_literal(name, "Array")) {
					op->extended_value = IS_ARRAY;
				} else if (zend_string_equals_literal(name, "Object")) {
					op->extended_value = IS_OBJECT;
				}
			}
			zval_ptr_dtor(&rv);
			goto unary_op;
		} else if (obj->ce == dd_ce_OpAssign) {
			zend_object *fetch = OBJ_PROP("fetch"), *var = OBJ_PROP("var");
			bool ref = BOOL_PROP("byRef");
			EMIT(ref ? ZEND_ASSIGN_REF : ZEND_ASSIGN);
			ASSIGN_NODE(var, 1);
			PROP_NODE("value", 2);
			if (fetch->ce != dd_ce_FetchTarget) {
				zend_uchar *opcode = &op->opcode;
				EMIT(ZEND_OP_DATA);
				obj = fetch;
				if (fetch->ce == dd_ce_FetchTargetDim) {
					*opcode = ZEND_ASSIGN_DIM;
					PROP_NODE("dim", 1);
				} else if (fetch->ce == dd_ce_FetchTargetObj) {
					*opcode = ref ? ZEND_ASSIGN_OBJ_REF : ZEND_ASSIGN_OBJ;
					PROP_NODE("prop", 1);
				} else if (fetch->ce == dd_ce_FetchTargetStaticProp) {
					*opcode = ref ? ZEND_ASSIGN_STATIC_PROP_REF : ZEND_ASSIGN_STATIC_PROP;
					PROP_NODE("prop", 1);
				}
			} else if (is_this_var(var)) {
				*err = "Cannot reassign $this.";
			}
			adjust_fetch_type(var, BP_VAR_W);
		} else if (obj->ce == dd_ce_OpQmAssign) {
			EMIT(ZEND_QM_ASSIGN);
			PROP_NODE("arg", 1);
		} else if (obj->ce == dd_ce_OpUnset) {
			zend_object *fetch = OBJ_PROP("fetch"), *var = OBJ_PROP("var");
			EMIT(var->ce == dd_ce_NamedVarNode ? ZEND_UNSET_CV : ZEND_UNSET_VAR);
			ASSIGN_NODE(var, 1);
			if (fetch->ce == dd_ce_FetchTargetDim) {
				op->opcode = ZEND_UNSET_DIM;
				PROP_NODE("dim", 2);
			} else if (fetch->ce == dd_ce_FetchTargetObj) {
				op->opcode = ZEND_UNSET_OBJ;
				PROP_NODE("prop", 2);
			} else if (fetch->ce == dd_ce_FetchTargetStaticProp) {
				op->opcode = ZEND_UNSET_STATIC_PROP;
				PROP_NODE("prop", 2);
			} else if (is_this_var(var)) {
				*err = "unset($this) is not allowed.";
				goto err;
			}
			adjust_fetch_type(var, BP_VAR_UNSET);
		} else if (obj->ce == dd_ce_OpIsset || obj->ce == dd_ce_OpEmpty) {
			zend_object *fetch = OBJ_PROP("fetch"), *var = OBJ_PROP("var");
			if (fetch->ce == dd_ce_FetchTarget && is_this_var(var) && this_guaranteed_exists(op_array)) {
				EMIT(ZEND_ISSET_ISEMPTY_THIS);
			} else {
				EMIT(var->ce == dd_ce_NamedVarNode ? ZEND_ISSET_ISEMPTY_CV : ZEND_ISSET_ISEMPTY_VAR);
				if (fetch->ce == dd_ce_FetchTargetDim) {
					op->opcode = ZEND_ISSET_ISEMPTY_DIM_OBJ;
					PROP_NODE("dim", 2);
				} else if (fetch->ce == dd_ce_FetchTargetObj) {
					op->opcode = ZEND_ISSET_ISEMPTY_PROP_OBJ;
					PROP_NODE("prop", 2);
				} else if (fetch->ce == dd_ce_FetchTargetStaticProp) {
					op->opcode = ZEND_ISSET_ISEMPTY_STATIC_PROP;
					PROP_NODE("prop", 2);
				}
				ASSIGN_NODE(var, 1);
			}
			if (obj->ce == dd_ce_OpEmpty) {
				op->extended_value |= ZEND_ISEMPTY;
			}
			adjust_fetch_type(var, BP_VAR_IS);
		} else if (obj->ce == dd_ce_OpFetchRead || obj->ce == dd_ce_OpFetchWrite) {
			zend_object *fetch = OBJ_PROP("fetch"), *var = OBJ_PROP("var");
			EMIT(ZEND_FETCH_R);
			if (fetch->ce == dd_ce_FetchTargetDim) {
				op->opcode = ZEND_FETCH_DIM_R;
				PROP_NODE("dim", 2);
			} else if (fetch->ce == dd_ce_FetchTargetObj) {
				op->opcode = ZEND_FETCH_OBJ_R; // TODO $this handling
				PROP_NODE("prop", 2);
			} else if (fetch->ce == dd_ce_FetchTargetStaticProp) {
				op->opcode = ZEND_FETCH_STATIC_PROP_R;
				PROP_NODE("prop", 2);
			}
			ASSIGN_NODE(var, 1);
		} else if (obj->ce == dd_ce_OpFetchList) {
			EMIT(BOOL_PROP("byRef") ? ZEND_FETCH_LIST_W : ZEND_FETCH_LIST_R);
			PROP_NODE("var", 1);
			if (op->opcode == ZEND_FETCH_LIST_W && (op->op1_type & (IS_VAR|IS_CV)) == 0) {
				*err = "Cannot fetch by-ref from non-referencable value";
				goto err;
			}
			PROP_NODE("dim", 2);
		} else if (obj->ce == dd_ce_OpMakeRef) {
			EMIT(ZEND_MAKE_REF);
			PROP_NODE("arg", 1);
		} else if (obj->ce == dd_ce_OpQmAssign) {
			EMIT(ZEND_QM_ASSIGN);
			PROP_NODE("arg", 1);
		} else if (obj->ce == dd_ce_OpPreInc || obj->ce == dd_ce_OpPostInc || obj->ce == dd_ce_OpPreDec || obj->ce == dd_ce_OpPostDec) {
			zend_object *fetch = OBJ_PROP("target"), *var = OBJ_PROP("var");
			uint32_t dim_tmp;
			if (fetch->ce == dd_ce_FetchTargetDim) {
				EMIT(ZEND_FETCH_DIM_R);
				op->extended_value = ZEND_FETCH_DIM_INCDEC;
				ASSIGN_NODE(var, 1);
				PROP_NODE("dim", 2);
				zend_hash_index_del(&tmps, (zend_ulong)obj);
				dim_tmp = T;
			}
			zend_uchar opcode;
			if (obj->ce == dd_ce_OpPreInc) opcode = ZEND_PRE_INC;
			else if (obj->ce == dd_ce_OpPostInc) opcode = ZEND_POST_INC;
			else if (obj->ce == dd_ce_OpPreDec) opcode = ZEND_PRE_DEC;
			else opcode = ZEND_POST_DEC;
			EMIT(opcode);
			if (fetch->ce == dd_ce_FetchTargetDim) {
				op->op1.var = dim_tmp;
			} else {
				ASSIGN_NODE(var, 1);
			}
			if (fetch->ce == dd_ce_FetchTargetObj) {
				if (obj->ce == dd_ce_OpPreInc) op->opcode = ZEND_PRE_INC_OBJ;
				else if (obj->ce == dd_ce_OpPostInc) op->opcode = ZEND_POST_INC_OBJ;
				else if (obj->ce == dd_ce_OpPreDec) op->opcode = ZEND_PRE_DEC_OBJ;
				else op->opcode = ZEND_POST_DEC_OBJ;
				PROP_NODE("prop", 2);
			} else if (fetch->ce == dd_ce_FetchTargetStaticProp) {
				if (obj->ce == dd_ce_OpPreInc) op->opcode = ZEND_PRE_INC_STATIC_PROP;
				else if (obj->ce == dd_ce_OpPostInc) op->opcode = ZEND_POST_INC_STATIC_PROP;
				else if (obj->ce == dd_ce_OpPreDec) op->opcode = ZEND_PRE_DEC_STATIC_PROP;
				else op->opcode = ZEND_POST_DEC_STATIC_PROP;
				PROP_NODE("prop", 2);
			}
			adjust_fetch_type(var, BP_VAR_RW);
		} else if (obj->ce == dd_ce_OpJmp) {
			EMIT(ZEND_JMP);
			op->op1.num = OBJ_PROP("target")->handle;
		} else if (obj->ce == dd_ce_OpJmpz || obj->ce == dd_ce_OpJmpzEx || obj->ce == dd_ce_OpJmpnz || obj->ce == dd_ce_OpJmpnzEx) {
			zend_uchar opcode;
			if (obj->ce == dd_ce_OpJmpz) opcode = ZEND_JMPZ;
			else if (obj->ce == dd_ce_OpJmpnz) opcode = ZEND_JMPNZ;
			else if (obj->ce == dd_ce_OpJmpzEx) opcode = ZEND_JMPZ_EX;
			else opcode = ZEND_JMPNZ_EX;
			EMIT(opcode);
			PROP_NODE("condition", 1);
			op->op2.num = OBJ_PROP("target")->handle;
		} else if (obj->ce == dd_ce_OpJmpNull) {
			EMIT(ZEND_JMP_NULL);
			PROP_NODE("condition", 1);
			op->op2.num = OBJ_PROP("target")->handle;
			op->extended_value = 0; // TODO: ???
			// TODO: writes into result node of _target_
		} else if (obj->ce == dd_ce_OpCase || obj->ce == dd_ce_OpCaseStrict) {
			EMIT(obj->ce == dd_ce_OpCase ? ZEND_CASE : ZEND_CASE_STRICT);
			PROP_NODE("switch", 1);
			PROP_NODE("compare", 2);
		} else if (obj->ce == dd_ce_OpMatchError) {
			EMIT(ZEND_MATCH_ERROR);
			PROP_NODE("switch", 1);
		} else if (obj->ce == dd_ce_OpCheckVar) {
			EMIT(ZEND_CHECK_VAR);
			PROP_NODE("var", 1);
		} else if (obj->ce == dd_ce_OpInitFcall) {
			zend_object *func = OBJ_PROP("function");
			if (func->ce == dd_ce_ConstNode) {
				zend_string *name = Z_STR_P(zend_read_property(obj->ce, obj, ZEND_STRL("value"), true, &rv));
				const char *ns_separator;
				if (ZSTR_VAL(name)[0] != '\\' && (ns_separator = zend_memrchr(ZSTR_VAL(name), '\\', ZSTR_LEN(name))) != NULL) {
					EMIT(ZEND_INIT_NS_FCALL_BY_NAME);
					ZVAL_STR_COPY(&literals[literal_count++], name);
					ZVAL_STR(&literals[literal_count++], zend_string_tolower(name));
					ZVAL_STR(&literals[literal_count++], zend_string_init(ns_separator + 1, ZSTR_VAL(name) + ZSTR_LEN(name) - ns_separator - 1, 0));
				} else {
					if (ZSTR_VAL(name)[0] == '\\') {
						name = zend_string_init(ZSTR_VAL(name) + 1, ZSTR_LEN(name) - 1, 0);
					}
					EMIT(ZEND_INIT_FCALL_BY_NAME);
					ZVAL_STR(&literals[literal_count++], name);
					ZVAL_STR(&literals[literal_count++], zend_string_tolower(name));
				}
			}
			op->extended_value = 0; // TODO: 	num args
		}
	} ZEND_HASH_FOREACH_END();

	// TODO pass two, adjust everything, and jump op nums are handles, see jumps hashtable

	return;
	err:
	efree(ops);
	zend_hash_destroy(&cvs);
	return;
}

PHP_FUNCTION(DDTrace_convert_op_array) {
	zend_string *func, *class = NULL;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_STR(func)
		Z_PARAM_OPTIONAL
		Z_PARAM_STR(class)
	ZEND_PARSE_PARAMETERS_END();

	HashTable *func_table = CG(function_table);
	if (class) {
		zend_class_entry *ce;
		if (!(ce = zend_hash_find_ptr_lc(CG(class_table), class))) {
			RETURN_NULL();
		}
		func_table = &ce->function_table;
	}

	zend_op_array *op_array = zend_hash_find_ptr_lc(func_table, func);
	if (!op_array || op_array->type != ZEND_USER_FUNCTION) {
		RETURN_NULL();
	}

	RETURN_OBJ(convert_op_array(op_array));
}

void ddtrace_transpile_minit() {
	register_classes();
	zend_register_functions(NULL, ext_functions, NULL, MODULE_PERSISTENT);
}


ZEND_METHOD(DDTrace_Op, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_Op, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_Op, arg3) { /* TODO */ }
ZEND_METHOD(DDTrace_UnaryOp, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_BinaryOp, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_BinaryOp, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_BinaryOp, arg3) { /* TODO */ }
ZEND_METHOD(DDTrace_IncDecOp, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_IncDecOp, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_JumpOp, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpAssign, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpAssign, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpAssign, arg3) { /* TODO */ }
ZEND_METHOD(DDTrace_OpUnset, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpUnset, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpIsset, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpIsset, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpEmpty, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpEmpty, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpQmAssign, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpJmpz, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpJmpnz, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpJmpzEx, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpJmpnzEx, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpJmpNull, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpCase, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpCase, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpCaseStrict, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpCaseStrict, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpMatchError, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpCheckVar, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpInitFcall, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpInitMethodCall, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpInitMethodCall, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpInitStaticMethodCall, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpInitStaticMethodCall, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpNew, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpSend, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpSendUnpack, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpReturn, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpFree, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpIncludeOrEval, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpInitArray, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpInitArray, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpAddArrayElement, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpAddArrayElement, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpAddArrayElement, arg3) { /* TODO */ }
ZEND_METHOD(DDTrace_OpAddArrayUnpack, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpAddArrayUnpack, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpFeReset, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpFeReset, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpFeFetch, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpFeFetch, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpFeFree, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpExit, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpFetchConstant, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpFetchClassConstant, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpFetchClassConstant, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpCatch, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpCatch, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpCatch, arg3) { /* TODO */ }
ZEND_METHOD(DDTrace_OpThrow, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpClone, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpEcho, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpInstanceof, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpInstanceof, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpDeclareFunction, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpDeclareClass, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpJmpSet, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpSeparate, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpFetchClassName, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpYield, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpYield, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpYieldFrom, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpCopyTmp, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpBindGlobal, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpBindGlobal, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpCoalesce, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpBindLexical, arg1) { /* TODO */ }
ZEND_METHOD(DDTrace_OpBindLexical, arg2) { /* TODO */ }
ZEND_METHOD(DDTrace_OpBindStatic, arg1) { /* TODO */ }
