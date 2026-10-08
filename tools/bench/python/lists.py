def main():
    l = []
    for i in range(1000000):
        l.append((i * 7919) % 1000003)
    total = 0
    for v in l:
        total += v
    for i in range(0, len(l), 2):
        l[i] = l[i] + 1
    biggest = 0
    for i in range(len(l)):
        if l[i] > biggest:
            biggest = l[i]
    print(f"{total} {biggest} {len(l[10:1010])}")


main()
