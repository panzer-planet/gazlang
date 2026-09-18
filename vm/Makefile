# gazvm, the GazLang VM in C. Only libc and libm.
#   make        vm/gazvm, optimised
#   make test   vm/build/gazvm-test, with AddressSanitizer and UndefinedBehaviorSanitizer,
#               which tests/CVMTest.php runs (it calls this itself)

CC ?= cc
WARNINGS = -std=c11 -Wall -Wextra -Wno-unused-parameter
SOURCES = value.c ops.c load.c vm.c builtins.c gc.c
HEADERS = gazvm.h

gazvm: $(SOURCES) $(HEADERS)
	$(CC) $(WARNINGS) -O2 -o $@ $(SOURCES) -lm -lpthread

test: build/gazvm-test

build/gazvm-test: $(SOURCES) $(HEADERS)
	@mkdir -p build
	$(CC) $(WARNINGS) -O1 -g -fno-omit-frame-pointer -fsanitize=address,undefined -fno-sanitize-recover=all -DGC_MINIMUM=64 -o $@ $(SOURCES) -lm -lpthread

# Collects cycles at every chance, for testing the collector (slow): GAZVM=vm/build/gazvm-stress php vm/progress.php
stress: build/gazvm-stress

build/gazvm-stress: $(SOURCES) $(HEADERS)
	@mkdir -p build
	$(CC) $(WARNINGS) -O1 -g -fno-omit-frame-pointer -fsanitize=address,undefined -fno-sanitize-recover=all -DGC_MINIMUM=1 -DGC_STRESS -o $@ $(SOURCES) -lm -lpthread

clean:
	rm -rf build gazvm

.PHONY: test stress clean

# Coverage, for vm/coverage.php
build/gazvm-cov: $(SOURCES) $(HEADERS)
	@mkdir -p build
	$(CC) $(WARNINGS) -O0 -g -fprofile-instr-generate -fcoverage-mapping -o $@ $(SOURCES) -lm -lpthread
