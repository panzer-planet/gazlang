class Vec:
    __slots__ = ("x", "y")

    def __init__(self, x, y):
        self.x = x
        self.y = y

    def add(self, o):
        return Vec(self.x + o.x, self.y + o.y)

    def dot(self, o):
        return self.x * o.x + self.y * o.y


def main():
    acc = Vec(0, 0)
    d = 0
    for i in range(500000):
        v = Vec(i, i % 13)
        acc = acc.add(v)
        d += v.dot(acc) % 1000
    print(f"{acc.x} {acc.y} {d}")


main()
