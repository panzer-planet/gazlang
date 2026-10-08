def main():
    s = ""
    for i in range(300000):
        s += "item" + str(i) + ","
    parts = s.split(",")
    count = 0
    for p in parts:
        if p.startswith("item1"):
            count += 1
    print(f"{len(s)} {count} {s.index('item299999')} {len(';'.join(parts))}")


main()
