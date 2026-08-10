// Файл: qty.go
// Назначение: арифметика количеств с 6 знаками (паритет PHP bccomp/bcadd/bcsub).
package qty

import (
	"fmt"
	"math"
	"strconv"
	"strings"
)

const scale = 6

// Normalize приводит значение к строке с фиксированной точностью.
func Normalize(v any) (string, bool) {
	if v == nil {
		return "", false
	}
	switch t := v.(type) {
	case string:
		s := strings.TrimSpace(t)
		if s == "" || !isNumeric(s) {
			return "", false
		}
		return format(s), true
	case float64:
		return format(strconv.FormatFloat(t, 'f', -1, 64)), true
	case int:
		return format(strconv.Itoa(t)), true
	case int64:
		return format(strconv.FormatInt(t, 10)), true
	default:
		return "", false
	}
}

func isNumeric(s string) bool {
	_, err := strconv.ParseFloat(s, 64)
	return err == nil
}

// Format нормализует строку количества до 6 знаков.
func Format(s string) string {
	return format(s)
}

func format(s string) string {
	f, err := strconv.ParseFloat(s, 64)
	if err != nil {
		return "0.000000"
	}
	return strconv.FormatFloat(f, 'f', scale, 64)
}

// Cmp сравнивает a и b: -1, 0, 1.
func Cmp(a, b string) int {
	fa, _ := strconv.ParseFloat(format(a), 64)
	fb, _ := strconv.ParseFloat(format(b), 64)
	diff := fa - fb
	if math.Abs(diff) < 1e-9 {
		return 0
	}
	if diff < 0 {
		return -1
	}
	return 1
}

func Add(a, b string) string {
	fa, _ := strconv.ParseFloat(format(a), 64)
	fb, _ := strconv.ParseFloat(format(b), 64)
	return format(strconv.FormatFloat(fa+fb, 'f', -1, 64))
}

func Sub(a, b string) string {
	fa, _ := strconv.ParseFloat(format(a), 64)
	fb, _ := strconv.ParseFloat(format(b), 64)
	return format(strconv.FormatFloat(fa-fb, 'f', -1, 64))
}

func Negate(a string) string {
	f, _ := strconv.ParseFloat(format(a), 64)
	return format(strconv.FormatFloat(-f, 'f', -1, 64))
}

func Equal(a, b string) bool {
	return Cmp(a, b) == 0
}

func Must(v any) string {
	s, ok := Normalize(v)
	if !ok {
		panic(fmt.Sprintf("qty: invalid %v", v))
	}
	return s
}
