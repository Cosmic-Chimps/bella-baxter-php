<?php

declare(strict_types=1);

namespace BellaBaxter\Tests;

use BellaBaxter\BaxterClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #1162 — the README is checked against the real classes. It documented an `enableE2ee` option, `clientId` /
 * `clientSecret` / `environmentSlug` options and a `getSecret()` method, none of which exist: a reader who
 * copied the Quick Start got an "Unknown named parameter" error on line one.
 *
 * Every ```php block must lint; every named argument passed to `new <SDK class>(...)` and every `$name:` a
 * Symfony ```yaml block wires into an SDK class must be a constructor parameter of that class; and every
 * `$client->method(` must be a public method of BaxterClient. The names come from Reflection, never a copied
 * list, so the test follows the code.
 */
final class ReadmeTest extends TestCase
{
    private const README = __DIR__ . '/../README.md';

    /** @return list<array{string, string}> [lang, code] */
    private static function blocks(): array
    {
        preg_match_all('/^```(php|yaml)\n(.*?)^```/ms', (string) file_get_contents(self::README), $m, PREG_SET_ORDER);
        return array_map(static fn (array $b): array => [$b[1], $b[2]], $m);
    }

    public static function phpBlocks(): array
    {
        $out = [];
        foreach (self::blocks() as $i => [$lang, $code]) {
            if ($lang === 'php') {
                $out["php block #{$i}: " . strtok(trim($code), "\n")] = [$code];
            }
        }
        return $out;
    }

    private static function source(string $code): string
    {
        return str_starts_with(ltrim($code), '<?php') ? $code : "<?php\n" . $code;
    }

    #[DataProvider('phpBlocks')]
    public function testEveryPhpSampleLints(string $code): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bella-readme-') . '.php';
        file_put_contents($file, self::source($code));
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $rc);
        unlink($file);
        self::assertSame(0, $rc, implode("\n", $output));
    }

    /** `use BellaBaxter\...` imports across the README (a fragment may rely on an earlier block's imports). */
    private static function sdkImports(): array
    {
        $map = [];
        foreach (self::blocks() as [$lang, $code]) {
            if ($lang === 'php' && preg_match_all('/^use\s+(BellaBaxter\\\\[\w\\\\]+)\s*;/m', $code, $m)) {
                foreach ($m[1] as $fqcn) {
                    $map[substr($fqcn, strrpos($fqcn, '\\') + 1)] = $fqcn;
                }
            }
        }
        return $map;
    }

    /** @return list<string> */
    private static function constructorParameters(string $fqcn): array
    {
        $ctor = (new \ReflectionClass($fqcn))->getConstructor();
        return $ctor === null ? [] : array_map(static fn (\ReflectionParameter $p) => $p->getName(), $ctor->getParameters());
    }

    #[DataProvider('phpBlocks')]
    public function testEveryNamedArgumentToAnSdkConstructorExists(string $code): void
    {
        $imports = self::sdkImports();
        $tokens  = array_values(array_filter(
            \PhpToken::tokenize(self::source($code)),
            static fn (\PhpToken $t) => !$t->isIgnorable(),
        ));
        for ($i = 0; $i < count($tokens); $i++) {
            if (!$tokens[$i]->is(T_NEW)) {
                continue;
            }
            $name = ltrim($tokens[$i + 1]->text ?? '', '\\');
            $fqcn = $imports[$name] ?? (str_starts_with($name, 'BellaBaxter\\') ? $name : null);
            if ($fqcn === null || ($tokens[$i + 2]->text ?? '') !== '(') {
                continue;
            }
            self::assertTrue(class_exists($fqcn), "README constructs {$fqcn}, which does not exist");
            $params = self::constructorParameters($fqcn);
            $depth  = 0;
            for ($j = $i + 2; $j < count($tokens); $j++) {
                $t = $tokens[$j]->text;
                if ($t === '(' || $t === '[' || $t === '{') {
                    $depth++;
                } elseif ($t === ')' || $t === ']' || $t === '}') {
                    if (--$depth === 0) {
                        break;
                    }
                } elseif ($depth === 1 && $tokens[$j]->is(T_STRING) && ($tokens[$j + 1]->text ?? '') === ':') {
                    self::assertContains($t, $params, "README passes `{$t}:` to {$fqcn}, which takes: " . implode(', ', $params));
                }
            }
        }
        $this->addToAssertionCount(1); // a block with no SDK constructor is fine
    }

    #[DataProvider('phpBlocks')]
    public function testEveryClientMethodTheReadmeCallsExists(string $code): void
    {
        preg_match_all('/\$client->(\w+)\s*\(/', $code, $m);
        foreach ($m[1] as $method) {
            self::assertTrue(
                method_exists(BaxterClient::class, $method) && (new \ReflectionMethod(BaxterClient::class, $method))->isPublic(),
                "README calls \$client->{$method}(), which BaxterClient does not have",
            );
        }
        $this->addToAssertionCount(1);
    }

    public function testSymfonyWiringNamesRealConstructorParameters(): void
    {
        $checked = 0;
        foreach (self::blocks() as [$lang, $code]) {
            if ($lang !== 'yaml') {
                continue;
            }
            $class = null;
            foreach (explode("\n", $code) as $line) {
                if (preg_match('/^(BellaBaxter\\\\[\w\\\\]+):\s*$/', $line, $c)) {
                    $class = $c[1];
                } elseif ($class !== null && preg_match('/^\s+\$(\w+):/', $line, $a)) {
                    self::assertContains($a[1], self::constructorParameters($class), "README wires \${$a[1]} into {$class}");
                    $checked++;
                }
            }
        }
        self::assertGreaterThan(0, $checked, 'the Symfony sample wires no SDK arguments — did the README move?');
    }
}
