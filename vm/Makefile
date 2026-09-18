# gazvm, the GazLang VM in C. Only libc and libm, plus od and awk to build in the compiler.
#   make        vm/gazvm, optimised
#   make test   vm/build/gazvm-test, with AddressSanitizer and UndefinedBehaviorSanitizer,
#               which tests/CVMTest.php runs (it calls this itself)

CC ?= cc
WARNINGS = -std=c11 -Wall -Wextra -Wno-unused-parameter
SOURCES = value.c ops.c load.c vm.c builtins.c gc.c
HEADERS = gazvm.h
# The self-hosted compiler's bytecode, checked in, built into the VM so it can run source
COMPILER = build/compiler.c

gazvm: $(SOURCES) $(HEADERS) $(COMPILER)
	$(CC) $(WARNINGS) -O2 -o $@ $(SOURCES) $(COMPILER) -lm -lpthread

# The bytes as a C array, written out by od: no escaping to get wrong (and no trigraphs, which
# -std=c11 turns on, and which the ??= in the compiler's bytecode would be)
$(COMPILER): ../selfhost/compile.gzb Makefile
	@mkdir -p build
	{ echo 'const unsigned char compiler_gzb[] = {'; od -An -v -tu1 $< | awk '{ for (i = 1; i <= NF; i++) printf "%s,", $$i; print "" }'; echo '0};'; \
	  echo 'const unsigned long compiler_gzb_size = sizeof compiler_gzb - 1;'; } > $@

test: build/gazvm-test

build/gazvm-test: $(SOURCES) $(HEADERS) $(COMPILER)
	@mkdir -p build
	$(CC) $(WARNINGS) -O1 -g -fno-omit-frame-pointer -fsanitize=address,undefined -fno-sanitize-recover=all -DGC_MINIMUM=64 -o $@ $(SOURCES) $(COMPILER) -lm -lpthread

# Collects cycles at every chance, for testing the collector (slow): GAZVM=vm/build/gazvm-stress php vm/progress.php
stress: build/gazvm-stress

build/gazvm-stress: $(SOURCES) $(HEADERS) $(COMPILER)
	@mkdir -p build
	$(CC) $(WARNINGS) -O1 -g -fno-omit-frame-pointer -fsanitize=address,undefined -fno-sanitize-recover=all -DGC_MINIMUM=1 -DGC_STRESS -o $@ $(SOURCES) $(COMPILER) -lm -lpthread

clean:
	rm -rf build gazvm

.PHONY: test stress clean

# Coverage, for vm/coverage.php
build/gazvm-cov: $(SOURCES) $(HEADERS) $(COMPILER)
	@mkdir -p build
	$(CC) $(WARNINGS) -O0 -g -fprofile-instr-generate -fcoverage-mapping -o $@ $(SOURCES) $(COMPILER) -lm -lpthread
