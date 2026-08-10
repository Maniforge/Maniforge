<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Безопасный разбор выражений (shunting-yard + RPN). Без eval().
 */
final class MathEvaluator
{
    private const MAX_LENGTH = 500;

    /** @var array<string, int> */
    private const FUNC_ARITY = [
        'sin' => 1, 'cos' => 1, 'tan' => 1,
        'asin' => 1, 'acos' => 1, 'atan' => 1,
        'sinh' => 1, 'cosh' => 1, 'tanh' => 1,
        'log' => 1, 'ln' => 1, 'sqrt' => 1, 'cbrt' => 1,
        'abs' => 1, 'floor' => 1, 'ceil' => 1, 'round' => 1,
        'sign' => 1, 'exp' => 1, 'fact' => 1,
        'ncr' => 2, 'npr' => 2,
    ];

    private const CONSTANTS = ['pi' => M_PI, 'e' => M_E];

    public function __construct(
        private readonly string $mode,
        private readonly string $angle = 'deg',
    ) {
    }

    public function evaluate(string $expression): string
    {
        $expression = trim($expression);
        if ($expression === '') {
            throw new \InvalidArgumentException('Пустое выражение');
        }
        if (strlen($expression) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('Выражение слишком длинное (макс. 500 символов)');
        }

        $this->validateWhitelist($expression);
        $tokens = $this->tokenize($expression);
        $rpn = $this->toRpn($tokens);

        return $this->formatResult($this->evalRpn($rpn));
    }

    private function validateWhitelist(string $expr): void
    {
        $patterns = [
            'basic' => '/^[0-9+\-*\/%().\s]+$/',
            'engineering' => '/^[0-9a-zA-Z+\-*\/^%().,\s]+$/',
            'math' => '/^[0-9a-zA-Z+\-*\/^%().,\s]+$/',
        ];
        $pattern = $patterns[$this->mode] ?? null;
        if ($pattern === null || !preg_match($pattern, $expr)) {
            throw new \InvalidArgumentException('Недопустимые символы для режима «' . $this->mode . '»');
        }

        if ($this->mode === 'basic' && preg_match('/[a-zA-Z]/', $expr)) {
            throw new \InvalidArgumentException('В обычном режиме функции и константы запрещены');
        }

        if (preg_match_all('/[a-zA-Z_][a-zA-Z0-9_]*/', $expr, $m)) {
            foreach ($m[0] as $name) {
                $lower = strtolower($name);
                if (isset(self::CONSTANTS[$lower])) {
                    if ($this->mode === 'basic') {
                        throw new \InvalidArgumentException('Константа «' . $name . '» недоступна в обычном режиме');
                    }
                    continue;
                }
                if (!isset(self::FUNC_ARITY[$lower])) {
                    throw new \InvalidArgumentException('Неизвестная функция: ' . $name);
                }
                if ($this->mode === 'engineering' && in_array($lower, ['sinh', 'cosh', 'tanh', 'floor', 'ceil', 'round', 'sign', 'exp', 'ncr', 'npr'], true)) {
                    throw new \InvalidArgumentException('Функция «' . $name . '» доступна только в математическом режиме');
                }
            }
        }
    }

    /**
     * @return list<array{type: string, value: string|float}>
     */
    private function tokenize(string $expr): array
    {
        $tokens = [];
        $len = strlen($expr);
        $i = 0;

        while ($i < $len) {
            $ch = $expr[$i];
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            if (ctype_digit($ch) || ($ch === '.' && $i + 1 < $len && ctype_digit($expr[$i + 1]))) {
                $start = $i;
                $dot = false;
                while ($i < $len && (ctype_digit($expr[$i]) || ($expr[$i] === '.' && !$dot && ($dot = true) === true))) {
                    $i++;
                }
                $num = substr($expr, $start, $i - $start);
                if ($num === '.' || substr_count($num, '.') > 1) {
                    throw new \InvalidArgumentException('Некорректное число');
                }
                $tokens[] = ['type' => 'num', 'value' => (float) $num];
                continue;
            }
            if (ctype_alpha($ch)) {
                $start = $i;
                while ($i < $len && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) {
                    $i++;
                }
                $name = strtolower(substr($expr, $start, $i - $start));
                if (isset(self::CONSTANTS[$name])) {
                    $tokens[] = ['type' => 'num', 'value' => self::CONSTANTS[$name]];
                } else {
                    $tokens[] = ['type' => 'func', 'value' => $name];
                }
                continue;
            }
            if ($ch === '(') {
                $tokens[] = ['type' => 'lparen', 'value' => '('];
                $i++;
                continue;
            }
            if ($ch === ')') {
                $tokens[] = ['type' => 'rparen', 'value' => ')'];
                $i++;
                continue;
            }
            if ($ch === ',') {
                $tokens[] = ['type' => 'comma', 'value' => ','];
                $i++;
                continue;
            }
            if (str_contains('+-*/%^', $ch)) {
                $tokens[] = ['type' => 'op', 'value' => $ch];
                $i++;
                continue;
            }
            throw new \InvalidArgumentException('Неожиданный символ: ' . $ch);
        }

        return $tokens;
    }

    /** @param list<array{type: string, value: string|float}> $tokens */
    private function toRpn(array $tokens): array
    {
        $output = [];
        $ops = [];
        $precedence = ['^' => 4, '%' => 3, '*' => 3, '/' => 3, '+' => 2, '-' => 2];
        $rightAssoc = ['^' => true];

        $prevType = 'start';
        foreach ($tokens as $token) {
            if ($token['type'] === 'num') {
                $output[] = $token;
                $prevType = 'num';
                continue;
            }
            if ($token['type'] === 'func') {
                $ops[] = $token;
                $prevType = 'func';
                continue;
            }
            if ($token['type'] === 'comma') {
                while ($ops !== [] && ($ops[array_key_last($ops)]['type'] ?? '') !== 'lparen') {
                    $output[] = array_pop($ops);
                }
                $prevType = 'comma';
                continue;
            }
            if ($token['type'] === 'op') {
                $op = (string) $token['value'];
                if ($op === '-' && in_array($prevType, ['start', 'op', 'lparen', 'comma'], true)) {
                    $ops[] = ['type' => 'op', 'value' => 'u-'];
                } elseif ($op === '+' && in_array($prevType, ['start', 'op', 'lparen', 'comma'], true)) {
                    // unary plus skip
                } else {
                    while (
                        $ops !== []
                        && ($ops[array_key_last($ops)]['type'] ?? '') === 'op'
                        && $this->shouldPopOp((string) $ops[array_key_last($ops)]['value'], $op, $precedence, $rightAssoc)
                    ) {
                        $output[] = array_pop($ops);
                    }
                    $ops[] = $token;
                }
                $prevType = 'op';
                continue;
            }
            if ($token['type'] === 'lparen') {
                $ops[] = $token;
                $prevType = 'lparen';
                continue;
            }
            if ($token['type'] === 'rparen') {
                while ($ops !== [] && ($ops[array_key_last($ops)]['type'] ?? '') !== 'lparen') {
                    $top = array_pop($ops);
                    if (($top['type'] ?? '') === 'lparen') {
                        break;
                    }
                    $output[] = $top;
                }
                if ($ops === [] || ($ops[array_key_last($ops)]['type'] ?? '') !== 'lparen') {
                    throw new \InvalidArgumentException('Несогласованные скобки');
                }
                array_pop($ops);
                if ($ops !== [] && ($ops[array_key_last($ops)]['type'] ?? '') === 'func') {
                    $output[] = array_pop($ops);
                }
                $prevType = 'num';
            }
        }

        while ($ops !== []) {
            $top = array_pop($ops);
            if (in_array($top['type'] ?? '', ['lparen', 'rparen'], true)) {
                throw new \InvalidArgumentException('Несогласованные скобки');
            }
            $output[] = $top;
        }

        return $output;
    }

    private function shouldPopOp(string $stackOp, string $curOp, array $precedence, array $rightAssoc): bool
    {
        if ($stackOp === 'u-') {
            return true;
        }
        $p1 = $precedence[$stackOp] ?? 0;
        $p2 = $precedence[$curOp] ?? 0;
        if ($p1 > $p2) {
            return true;
        }
        if ($p1 === $p2) {
            return !($rightAssoc[$curOp] ?? false);
        }
        return false;
    }

  /** @param list<array{type: string, value: string|float}> $rpn */
    private function evalRpn(array $rpn): float
    {
        $stack = [];
        foreach ($rpn as $token) {
            if ($token['type'] === 'num') {
                $stack[] = (float) $token['value'];
                continue;
            }
            if ($token['type'] === 'op') {
                $op = (string) $token['value'];
                if ($op === 'u-') {
                    if (count($stack) < 1) {
                        throw new \InvalidArgumentException('Недостаточно операндов');
                    }
                    $stack[] = -array_pop($stack);
                    continue;
                }
                if (count($stack) < 2) {
                    throw new \InvalidArgumentException('Недостаточно операндов');
                }
                $b = array_pop($stack);
                $a = array_pop($stack);
                $stack[] = match ($op) {
                    '+' => $a + $b,
                    '-' => $a - $b,
                    '*' => $a * $b,
                    '/' => $this->div($a, $b),
                    '%' => $this->mod($a, $b),
                    '^' => $a ** $b,
                    default => throw new \InvalidArgumentException('Неизвестный оператор'),
                };
                continue;
            }
            if ($token['type'] === 'func') {
                $name = (string) $token['value'];
                $arity = self::FUNC_ARITY[$name];
                if (count($stack) < $arity) {
                    throw new \InvalidArgumentException('Недостаточно аргументов для ' . $name);
                }
                $args = [];
                for ($j = 0; $j < $arity; $j++) {
                    array_unshift($args, array_pop($stack));
                }
                $stack[] = $this->callFunc($name, $args);
            }
        }
        if (count($stack) !== 1) {
            throw new \InvalidArgumentException('Некорректное выражение');
        }
        $result = $stack[0];
        if (!is_finite($result)) {
            throw new \InvalidArgumentException('Результат не определён (бесконечность или NaN)');
        }
        return $result;
    }

    private function div(float $a, float $b): float
    {
        if (abs($b) < 1e-15) {
            throw new \InvalidArgumentException('Деление на ноль');
        }
        return $a / $b;
    }

    private function mod(float $a, float $b): float
    {
        if (abs($b) < 1e-15) {
            throw new \InvalidArgumentException('Деление на ноль');
        }
        return fmod($a, $b);
    }

    /** @param list<float> $args */
    private function callFunc(string $name, array $args): float
    {
        $x = $args[0];
        $toRad = fn (float $deg): float => $deg * M_PI / 180;
        $angleIn = $this->angle === 'deg' ? $toRad($x) : $x;

        return match ($name) {
            'sin' => sin($angleIn),
            'cos' => cos($angleIn),
            'tan' => $this->tanSafe($angleIn),
            'asin' => $this->asinOut(asin($this->clamp($x, -1, 1))),
            'acos' => $this->acosOut(acos($this->clamp($x, -1, 1))),
            'atan' => $this->atanOut(atan($x)),
            'sinh' => sinh($x),
            'cosh' => cosh($x),
            'tanh' => tanh($x),
            'log' => $this->log10($x),
            'ln' => $this->ln($x),
            'sqrt' => $this->sqrt($x),
            'cbrt' => $x < 0 ? -((- $x) ** (1 / 3)) : $x ** (1 / 3),
            'abs' => abs($x),
            'floor' => floor($x),
            'ceil' => ceil($x),
            'round' => round($x),
            'sign' => $x > 0 ? 1.0 : ($x < 0 ? -1.0 : 0.0),
            'exp' => exp($x),
            'fact' => $this->factorial($x),
            'ncr' => $this->nCr($args[0], $args[1]),
            'npr' => $this->nPr($args[0], $args[1]),
            default => throw new \InvalidArgumentException('Неизвестная функция'),
        };
    }

    private function clamp(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }

    private function tanSafe(float $rad): float
    {
        $c = cos($rad);
        if (abs($c) < 1e-12) {
            throw new \InvalidArgumentException('Тангенс не определён для данного угла');
        }
        return sin($rad) / $c;
    }

    private function asinOut(float $rad): float
    {
        return $this->angle === 'deg' ? $rad * 180 / M_PI : $rad;
    }

    private function acosOut(float $rad): float
    {
        return $this->angle === 'deg' ? $rad * 180 / M_PI : $rad;
    }

    private function atanOut(float $rad): float
    {
        return $this->angle === 'deg' ? $rad * 180 / M_PI : $rad;
    }

    private function ln(float $x): float
    {
        if ($x <= 0) {
            throw new \InvalidArgumentException('ln определён только для x > 0');
        }
        return log($x);
    }

    private function log10(float $x): float
    {
        if ($x <= 0) {
            throw new \InvalidArgumentException('log определён только для x > 0');
        }
        return log10($x);
    }

    private function sqrt(float $x): float
    {
        if ($x < 0) {
            throw new \InvalidArgumentException('Корень из отрицательного числа');
        }
        return sqrt($x);
    }

    private function factorial(float $x): float
    {
        if ($x < 0 || abs($x - round($x)) > 1e-9) {
            throw new \InvalidArgumentException('Факториал только для целого n ≥ 0');
        }
        $n = (int) round($x);
        if ($n > 170) {
            throw new \InvalidArgumentException('n слишком велико для факториала');
        }
        $r = 1.0;
        for ($i = 2; $i <= $n; $i++) {
            $r *= $i;
        }
        return $r;
    }

    private function nCr(float $n, float $k): float
    {
        $ni = $this->intParam($n, 'n');
        $ki = $this->intParam($k, 'k');
        if ($ki > $ni) {
            throw new \InvalidArgumentException('Для nCr требуется 0 ≤ k ≤ n');
        }
        return $this->factorial((float) $ni) / ($this->factorial((float) $ki) * $this->factorial((float) ($ni - $ki)));
    }

    private function nPr(float $n, float $k): float
    {
        $ni = $this->intParam($n, 'n');
        $ki = $this->intParam($k, 'k');
        if ($ki > $ni) {
            throw new \InvalidArgumentException('Для nPr требуется 0 ≤ k ≤ n');
        }
        return $this->factorial((float) $ni) / $this->factorial((float) ($ni - $ki));
    }

    private function intParam(float $v, string $label): int
    {
        if ($v < 0 || abs($v - round($v)) > 1e-9) {
            throw new \InvalidArgumentException($label . ' должно быть целым ≥ 0');
        }
        $i = (int) round($v);
        if ($i > 170) {
            throw new \InvalidArgumentException($label . ' слишком велико');
        }
        return $i;
    }

    private function formatResult(float $value): string
    {
        if (abs($value) < 1e-12) {
            $value = 0.0;
        }
        if (abs($value) >= 1e15 || (abs($value) > 0 && abs($value) < 1e-10)) {
            return sprintf('%.10e', $value);
        }
        $s = number_format($value, 12, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return $s === '' || $s === '-' ? '0' : $s;
    }
}
