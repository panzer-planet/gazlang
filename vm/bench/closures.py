from functools import cmp_to_key, reduce


def main():
    l = []
    for i in range(200000):
        l.append(i)
    sq = list(map(lambda x: x * x, l))
    even = list(filter(lambda x: x % 2 == 0, sq))
    total = reduce(lambda a, b: a + b, even, 0)
    s = sorted(l[0:20000], key=cmp_to_key(lambda a, b: (b > a) - (b < a)))
    print(f"{total} {s[0]}")


main()
