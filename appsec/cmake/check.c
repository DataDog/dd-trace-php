int foo(void);
int foo() { return 42; }
void get_module();
void get_module(void) {}
int main() {
	return foo();
}
