def main():
    total = 0
    for i in range(10000000):
        total += i * i % 7
    print(total)


main()
