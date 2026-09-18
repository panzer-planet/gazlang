# gazvm, the GazLang VM in C. Only libc and libm.
#   make        vm/gazvm, optimised
#   make test   vm/build/gazvm-test, with AddressSanitizer and UndefinedBehaviorSanitizer,
#               which tests/CVMTest.php runs (it calls this itself)

CC ?= cc
WARNINGS = -std=c11 -Wall -Wextra -Wno-unused-parameter
SOURCES = value.c ops.c load.c vm.c builtins.c
HEADERS = gazvm.h

gazvm: $(SOURCES) $(HEADERS)
	$(CC) $(WARNINGS) -O2 -o $@ $(SOURCES) -lm -lpthread

test: build/gazvm-test

build/gazvm-test: $(SOURCES) $(HEADERS)
	@mkdir -p build
	$(CC) $(WARNINGS) -O1 -g -fno-omit-frame-pointer -fsanitize=address,undefined -fno-sanitize-recover=all -o $@ $(SOURCES) -lm -lpthread

clean:
	rm -rf build gazvm

.PHONY: test clean

# Coverage, for vm/coverage.php
build/gazvm-cov: $(SOURCES) $(HEADERS)
	@mkdir -p build
	$(CC) $(WARNINGS) -O0 -g -fprofile-instr-generate -fcoverage-mapping -o $@ $(SOURCES) -lm -lpthread
