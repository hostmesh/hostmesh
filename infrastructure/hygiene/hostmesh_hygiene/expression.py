# SPDX-License-Identifier: Apache-2.0
"""SPDX license expressions: can one be met with allowed licenses only?

The grammar is the one of the SPDX specification, annex "SPDX license
expressions": identifiers, `WITH`, `AND`, `OR` and parentheses, with `AND`
binding tighter than `OR`. Identifiers are compared exactly: `GPL-2.0+` and
`mit` are identifiers of their own, and neither is `MIT`.
"""

import re
from collections.abc import Collection

_TOKEN = re.compile(r"\s*(\(|\)|[A-Za-z0-9.+-]+)")
# What cannot stand where a license identifier is expected.
_RESERVED = frozenset({"AND", "OR", "WITH", "(", ")"})


class InvalidExpression(ValueError):  # noqa: N818 - reads as a statement
    """The text is empty or is not an SPDX license expression."""


def satisfied(expression: str, allowed: Collection[str]) -> bool:
    """True when the expression can be met with licenses from `allowed` only."""
    parser = _Parser(expression, frozenset(allowed))
    result = parser.any_of()
    if parser.position != len(parser.tokens):
        raise InvalidExpression(expression)
    return result


class _Parser:
    def __init__(self, expression: str, allowed: frozenset[str]) -> None:
        self.expression = expression
        self.allowed = allowed
        self.tokens = _tokens(expression)
        self.position = 0

    def _peek(self) -> str | None:
        return self.tokens[self.position] if self.position < len(self.tokens) else None

    def _next(self) -> str:
        token = self._peek()
        if token is None:
            raise InvalidExpression(self.expression)
        self.position += 1
        return token

    def _identifier(self) -> str:
        identifier = self._next()
        if identifier in _RESERVED:
            raise InvalidExpression(self.expression)
        return identifier

    def any_of(self) -> bool:
        result = self.all_of()
        while self._peek() == "OR":
            self._next()
            # Both sides are parsed even when the left one passes: a syntax
            # error on the right must not hide behind it.
            right = self.all_of()
            result = result or right
        return result

    def all_of(self) -> bool:
        result = self.atom()
        while self._peek() == "AND":
            self._next()
            right = self.atom()
            result = result and right
        return result

    def atom(self) -> bool:
        if self._peek() == "(":
            self._next()
            result = self.any_of()
            if self._next() != ")":
                raise InvalidExpression(self.expression)
            return result
        license_id = self._identifier()
        if self._peek() == "WITH":
            self._next()
            return f"{license_id} WITH {self._identifier()}" in self.allowed
        return license_id in self.allowed


def _tokens(expression: str) -> list[str]:
    text = expression.strip()
    tokens: list[str] = []
    position = 0
    while position < len(text):
        match = _TOKEN.match(text, position)
        if match is None:
            raise InvalidExpression(expression)
        tokens.append(match.group(1))
        position = match.end()
    if not tokens:
        raise InvalidExpression(expression)
    return tokens
