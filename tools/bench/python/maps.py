def main():
    counts = {}
    for i in range(500000):
        word = "w" + str(i * 31 % 5003)
        if word not in counts:
            counts[word] = 0
        counts[word] += 1
    total = 0
    for w, c in counts.items():
        total += c
    print(f"{len(counts)} {total} {counts['w17']}")


main()
