# gazlang, the GazLang VM in C with the self-hosted compiler built in. libc, libm and OpenSSL
# (for TLS; make TLS=0 leaves it out, so a C compiler is all it takes), plus od and awk to build
# in the compiler.
#   make           bin/gazlang, optimised
#   make TLS=0     the same without OpenSSL: https and socket_open(..., true) are errors
#   make compiler  rebuild compiler/gazlang.gzb from compiler/ with bin/gazlang alone (see below)
#   make pgo       bin/gazlang with profile-guided optimisation (see below)
#   make test      vm/build/gazvm-test, with AddressSanitizer and UndefinedBehaviorSanitizer,
#                  which tests/CVMTest.php runs (it calls this itself)

CC ?= cc
# _DEFAULT_SOURCE: glibc hides POSIX and BSD functions (open_memstream, realpath, memmem) under
# -std=c11 without it; macOS ignores it
FLAGS = -std=c11 -D_DEFAULT_SOURCE -Wall -Wextra -Wno-unused-parameter
SOURCES = value.c ops.c load.c vm.c builtins.c gc.c net.c
LIBS = -lm -lpthread

# OpenSSL: from pkg-config, else Homebrew's (keg-only, so not on the default paths), else the
# system's headers
TLS ?= 1
ifeq ($(TLS),1)
  SSL_FLAGS := $(shell pkg-config --cflags openssl 2>/dev/null)
  SSL_LIBS := $(shell pkg-config --libs openssl 2>/dev/null)
  ifeq ($(SSL_LIBS),)
    BREW_SSL := $(shell brew --prefix openssl@3 2>/dev/null)
    ifneq ($(wildcard $(BREW_SSL)/include/openssl/ssl.h),)
      SSL_FLAGS := -I$(BREW_SSL)/include
      SSL_LIBS := -L$(BREW_SSL)/lib -lssl -lcrypto
    else ifneq ($(wildcard /usr/include/openssl/ssl.h),)
      SSL_LIBS := -lssl -lcrypto
    else
      $(error OpenSSL not found: install it (apt install libssl-dev, brew install openssl@3), or build without TLS: make TLS=0)
    endif
  endif
  FLAGS += -DGAZ_TLS $(SSL_FLAGS)
  LIBS += $(SSL_LIBS)
endif

HEADERS = gazvm.h
# The self-hosted compiler's bytecode, checked in, built into the VM so it can run source
COMPILER = build/compiler.c
GAZLANG = ../bin/gazlang

# The flags as a file, rewritten only when they change, and every binary depends on it, so
# switching TLS rebuilds. The binaries go too: make 3.81 (macOS's) compares times to the second,
# and a build started in the second the last one finished would see nothing newer.
CONFIG = build/config
$(shell mkdir -p build; echo '$(FLAGS) $(LIBS)' | cmp -s - $(CONFIG) || { echo '$(FLAGS) $(LIBS)' > $(CONFIG); rm -f $(GAZLANG) build/gazvm-test build/gazvm-stress build/gazvm-cov; })

all: $(GAZLANG)

$(GAZLANG): $(SOURCES) $(HEADERS) $(COMPILER) $(CONFIG)
	@mkdir -p ../bin
	$(CC) $(FLAGS) -O2 -o $@ $(SOURCES) $(COMPILER) $(LIBS)

# The bytes as a C array, written out by od: no escaping to get wrong (and no trigraphs, which
# -std=c11 turns on, and which the ??= in the compiler's bytecode would be)
$(COMPILER): ../compiler/gazlang.gzb Makefile
	@mkdir -p build
	{ echo 'const unsigned char compiler_gzb[] = {'; od -An -v -tu1 $< | awk '{ for (i = 1; i <= NF; i++) printf "%s,", $$i; print "" }'; echo '0};'; \
	  echo 'const unsigned long compiler_gzb_size = sizeof compiler_gzb - 1;'; } > $@

# make compiler: after editing compiler/, rebuild compiler/gazlang.gzb with bin/gazlang alone, in three
# stages: the current compiler compiles the new one (stage 1), which compiles itself (stage 2),
# which compiles itself again (stage 3). Stage 2 must equal stage 3: stage 1 was written by the
# old code generator, so it may differ by design, but a compiler whose output depends on how it
# was itself compiled has a bug. Only then is stage 2 copied over gazlang.gzb and bin/gazlang rebuilt,
# so a broken edit fails here and leaves bin/gazlang able to compile its fix. An explicit target, not
# a dependency of gazlang.gzb: a fresh clone's timestamps would have plain make try it with a
# bin/gazlang that needs gazlang.gzb to be built.
# ponytail: a stage's own runtime errors name build/bootstrap/ as the directory of its source
# files, since bytecode paths resolve against the bytecode file; the line numbers are right
BOOT = build/bootstrap
COMPILER_SOURCE = ../compiler/gazlang.gaz

compiler: $(GAZLANG)
	@mkdir -p $(BOOT)
	$(GAZLANG) -c -f $(COMPILER_SOURCE) > $(BOOT)/stage1.gzb
	$(GAZLANG) -f $(BOOT)/stage1.gzb -- code $(COMPILER_SOURCE) > $(BOOT)/stage2.gzb
	$(GAZLANG) -f $(BOOT)/stage2.gzb -- code $(COMPILER_SOURCE) > $(BOOT)/stage3.gzb
	@cmp -s $(BOOT)/stage2.gzb $(BOOT)/stage3.gzb || { echo "stage 2 differs from stage 3: the compiler's output depends on how it was compiled; compiler/gazlang.gzb is unchanged" >&2; exit 1; }
	@if cmp -s $(BOOT)/stage2.gzb ../compiler/gazlang.gzb; then echo "compiler/gazlang.gzb is up to date"; \
	 else cp $(BOOT)/stage2.gzb ../compiler/gazlang.gzb && $(MAKE) all; fi

# make pgo: bin/gazlang with profile-guided optimisation, opt-in so plain make still needs nothing
# but a C compiler. An instrumented build runs the compiler compiling itself, an ast of the
# parser and every example (failures ignored), and the optimised build is compiled again with
# what it counted. Not vm/bench, so the benchmarks stay an honest test. Everything is in
# build/pgo. Both builds write the same output name, since gcc names its .gcda files after it.
PGO = build/pgo
ifneq ($(findstring clang,$(shell $(CC) --version 2>/dev/null)),)
  PGO_GENERATE = -fprofile-instr-generate
  PGO_USE = -fprofile-instr-use=$(PGO)/merged.profdata
  # macOS has llvm-profdata only through xcrun
  PGO_MERGE = $(if $(shell command -v xcrun),xcrun) llvm-profdata merge -o $(PGO)/merged.profdata $(PGO)/*.profraw
else
  PGO_GENERATE = -fprofile-generate -fprofile-dir=$(abspath $(PGO))
  PGO_USE = -fprofile-use -fprofile-dir=$(abspath $(PGO))
  PGO_MERGE = true
endif

pgo: $(COMPILER) $(CONFIG)
	rm -rf $(PGO) && mkdir -p $(PGO) ../bin
	$(CC) $(FLAGS) -O2 $(PGO_GENERATE) -o $(PGO)/gazlang $(SOURCES) $(COMPILER) $(LIBS)
	cd .. && export LLVM_PROFILE_FILE=$(abspath $(PGO))/%p.profraw && \
	  vm/$(PGO)/gazlang -f compiler/gazlang.gaz -- code compiler/gazlang.gaz > /dev/null && \
	  vm/$(PGO)/gazlang -f compiler/gazlang.gaz -- ast compiler/parser.gaz > /dev/null && \
	  for f in examples/*.gaz; do vm/$(PGO)/gazlang -f $$f < /dev/null > /dev/null 2>&1 || true; done
	$(PGO_MERGE)
	$(CC) $(FLAGS) -O2 $(PGO_USE) -o $(PGO)/gazlang $(SOURCES) $(COMPILER) $(LIBS)
	cp $(PGO)/gazlang $(GAZLANG)

test: build/gazvm-test

build/gazvm-test: $(SOURCES) $(HEADERS) $(COMPILER) $(CONFIG)
	@mkdir -p build
	$(CC) $(FLAGS) -O1 -g -fno-omit-frame-pointer -fsanitize=address,undefined -fno-sanitize-recover=all -DGC_MINIMUM=64 -o $@ $(SOURCES) $(COMPILER) $(LIBS)

# Collects cycles at every chance, for testing the collector (slow): GAZVM=vm/build/gazvm-stress php vm/progress.php
stress: build/gazvm-stress

build/gazvm-stress: $(SOURCES) $(HEADERS) $(COMPILER) $(CONFIG)
	@mkdir -p build
	$(CC) $(FLAGS) -O1 -g -fno-omit-frame-pointer -fsanitize=address,undefined -fno-sanitize-recover=all -DGC_MINIMUM=1 -DGC_STRESS -o $@ $(SOURCES) $(COMPILER) $(LIBS)

clean:
	rm -rf build $(GAZLANG)

.PHONY: all compiler pgo test stress clean

# Coverage, for vm/coverage.php
build/gazvm-cov: $(SOURCES) $(HEADERS) $(COMPILER) $(CONFIG)
	@mkdir -p build
	$(CC) $(FLAGS) -O0 -g -fprofile-instr-generate -fcoverage-mapping -o $@ $(SOURCES) $(COMPILER) $(LIBS)
