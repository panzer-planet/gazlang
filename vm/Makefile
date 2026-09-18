# gazvm, the GazLang VM in C. Only libc and libm, plus od and awk to build in the compiler.
#   make           vm/gazvm, optimised
#   make compiler  rebuild ../selfhost/gazlang.gzb from selfhost/ with gazvm alone (see below)
#   make test      vm/build/gazvm-test, with AddressSanitizer and UndefinedBehaviorSanitizer,
#                  which tests/CVMTest.php runs (it calls this itself)

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
$(COMPILER): ../selfhost/gazlang.gzb Makefile
	@mkdir -p build
	{ echo 'const unsigned char compiler_gzb[] = {'; od -An -v -tu1 $< | awk '{ for (i = 1; i <= NF; i++) printf "%s,", $$i; print "" }'; echo '0};'; \
	  echo 'const unsigned long compiler_gzb_size = sizeof compiler_gzb - 1;'; } > $@

# make compiler: after editing selfhost/, rebuild selfhost/gazlang.gzb with gazvm alone, in three
# stages: the current compiler compiles the new one (stage 1), which compiles itself (stage 2),
# which compiles itself again (stage 3). Stage 2 must equal stage 3: stage 1 was written by the
# old code generator, so it may differ by design, but a compiler whose output depends on how it
# was itself compiled has a bug. Only then is stage 2 copied over gazlang.gzb and gazvm rebuilt,
# so a broken edit fails here and leaves gazvm able to compile its fix. An explicit target, not
# a dependency of gazlang.gzb: a fresh clone's timestamps would have plain make try it with a
# gazvm that needs gazlang.gzb to be built.
# ponytail: a stage's own runtime errors name build/bootstrap/ as the directory of its source
# files, since bytecode paths resolve against the bytecode file; the line numbers are right
BOOT = build/bootstrap
SELFHOST = ../selfhost/gazlang.gaz

compiler: gazvm
	@mkdir -p $(BOOT)
	./gazvm -c -f $(SELFHOST) > $(BOOT)/stage1.gzb
	./gazvm -f $(BOOT)/stage1.gzb -- code $(SELFHOST) > $(BOOT)/stage2.gzb
	./gazvm -f $(BOOT)/stage2.gzb -- code $(SELFHOST) > $(BOOT)/stage3.gzb
	@cmp -s $(BOOT)/stage2.gzb $(BOOT)/stage3.gzb || { echo "stage 2 differs from stage 3: the compiler's output depends on how it was compiled; selfhost/gazlang.gzb is unchanged" >&2; exit 1; }
	@if cmp -s $(BOOT)/stage2.gzb ../selfhost/gazlang.gzb; then echo "selfhost/gazlang.gzb is up to date"; \
	 else cp $(BOOT)/stage2.gzb ../selfhost/gazlang.gzb && $(MAKE) gazvm; fi

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

.PHONY: compiler test stress clean

# Coverage, for vm/coverage.php
build/gazvm-cov: $(SOURCES) $(HEADERS) $(COMPILER)
	@mkdir -p build
	$(CC) $(WARNINGS) -O0 -g -fprofile-instr-generate -fcoverage-mapping -o $@ $(SOURCES) $(COMPILER) -lm -lpthread
