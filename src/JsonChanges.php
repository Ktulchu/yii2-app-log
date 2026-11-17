<?php
declare(strict_types=1);

namespace Ktulchu\Yii2AppLog;

use yii\base\Model;
use yii\db\Connection;

/**
 * Optional presentation built from raw PostgreSQL values, never from AR JSON hydration.
 * @phpstan-type Node array{present: bool, sql_null: bool, raw: ?string, type: string}
 * @phpstan-type Options array{label?: string, value?: callable, preFilter?: callable, type?: string, model?: class-string<Model>, only?: list<string>, except?: list<string>, functions?: array<string, callable>}
 */
final class JsonChanges
{
    private ?Model $labelModel = null;

    /** @param Options $options */
    public function __construct(private Connection $db, private array $options, private bool $binary)
    {
        if (isset($options['model'])) {
            $class = $options['model'];
            $this->labelModel = new $class();
        }
    }

    /** @param array<string, mixed> $options */
    public static function validate(array $options, string $dbType): void
    {
        $jsonKeys = array_intersect(['type', 'model', 'only', 'except', 'functions'], array_keys($options));
        if ($jsonKeys === []) { return; }
        if (!in_array($dbType, ['json', 'jsonb'], true)
            || !in_array($options['type'] ?? null, AppLogHelper::JSON_TYPES, true)) {
            throw new \LogicException('JSON presentation requires a JSON/JSONB column and a supported type.');
        }
        if (array_key_exists('model', $options) && (!is_string($options['model']) || !is_subclass_of($options['model'], Model::class))) {
            throw new \LogicException('JSON model must be a yii\\base\\Model class.');
        }
        foreach (['only', 'except'] as $filter) {
            if (array_key_exists($filter, $options)) {
                if (!is_array($options[$filter]) || !array_is_list($options[$filter])) {
                    throw new \LogicException('JSON ' . $filter . ' must be a list of field names.');
                }
                foreach ($options[$filter] as $name) {
                    if (!is_string($name)) { throw new \LogicException('JSON filter names must be strings.'); }
                }
            }
        }
        if (array_key_exists('functions', $options)) {
            if (!is_array($options['functions'])) { throw new \LogicException('JSON functions must be a callback map.'); }
            foreach ($options['functions'] as $callback) {
                if (!is_callable($callback)) { throw new \LogicException('JSON functions must contain callables.'); }
            }
        }
    }

    public function build(?string $oldRaw, ?string $newRaw, bool $oldPresent, bool $newPresent): string
    {
        $old = $this->node($oldRaw, $oldPresent);
        $new = $this->node($newRaw, $newPresent);
        $type = $this->options['type'];
        if (in_array($type, [AppLogHelper::JSON_ASSOC_ARRAYS_COLLECTION, AppLogHelper::JSON_SINGLE_SIMPLE_ARRAY], true)) {
            // No record matching or positional diff. Project each side independently.
            if (!$this->different($old, $new)) { return '[]'; }
            $collection = $type === AppLogHelper::JSON_ASSOC_ARRAYS_COLLECTION;
            [$oldProjection] = $this->project($old, $collection, false);
            [$newProjection] = $this->project($new, $collection, false);
            if (!$this->different($oldProjection, $newProjection)) { return '[]'; }
            [, $oldDisplay] = $this->project($old, $collection, true);
            [, $newDisplay] = $this->project($new, $collection, true);
            return '[' . $this->entry([], $oldProjection, $newProjection, $oldDisplay, $newDisplay) . ']';
        }
        return '[' . implode(',', $this->objectChanges($old, $new, [])) . ']';
    }

    /** @return Node */
    private function node(?string $raw, bool $present = true): array
    {
        $type = !$present ? 'missing' : ($raw === null ? 'sql_null' : (string) $this->db->createCommand(
            'SELECT json_typeof(CAST(:value AS json))', [':value' => $raw]
        )->queryScalar());
        return ['present' => $present, 'sql_null' => $present && $raw === null, 'raw' => $raw, 'type' => $type];
    }

    /** @param Node $node
     * @return array<string|int, Node>
     */
    private function children(array $node): array
    {
        if (!in_array($node['type'], ['object', 'array'], true)) { return []; }
        $source = $node['type'] === 'object'
            ? 'SELECT key, value::text AS raw, json_typeof(value) AS type FROM json_each(CAST(:value AS json))'
            : 'SELECT (ordinality - 1)::text AS key, value::text AS raw, json_typeof(value) AS type FROM json_array_elements(CAST(:value AS json)) WITH ORDINALITY';
        $result = [];
        foreach ($this->db->createCommand($source, [':value' => $node['raw']])->queryAll() as $row) {
            $result[$row['key']] = ['present' => true, 'sql_null' => false, 'raw' => $row['raw'], 'type' => $row['type']];
        }
        return $result;
    }

    /** @param Node $old
     * @param Node $new */
    private function different(array $old, array $new): bool
    {
        if ($old['present'] !== $new['present'] || $old['sql_null'] !== $new['sql_null']) { return true; }
        if (!$old['present'] || $old['sql_null']) { return false; }
        if (!$this->binary) { return $old['raw'] !== $new['raw']; }
        return (bool) $this->db->createCommand(
            'SELECT CAST(:old AS jsonb) IS DISTINCT FROM CAST(:new AS jsonb)',
            [':old' => $old['raw'], ':new' => $new['raw']]
        )->queryScalar();
    }

    /** @param Node $old
     * @param Node $new
     * @param list<string> $path
     * @return list<string>
     */
    private function objectChanges(array $old, array $new, array $path): array
    {
        if (!$this->different($old, $new)) { return []; }
        if ($path === [] && $old['type'] !== $new['type']
            && !in_array('missing', [$old['type'], $new['type']], true)) {
            [$a, $aDisplay] = $this->projectObject($old);
            [$b, $bDisplay] = $this->projectObject($new);
            return [$this->entry($path, $a, $b, $aDisplay, $bDisplay)];
        }
        if ($old['type'] !== 'object' || $new['type'] !== 'object') {
            // Add/remove objects can still be projected by named members; type transitions stay explicit.
            if (($old['type'] === 'missing' && $new['type'] === 'object')
                || ($new['type'] === 'missing' && $old['type'] === 'object')) {
                if ($this->children($old) === [] && $this->children($new) === []) {
                    return [$this->entry($path, $old, $new)];
                }
            } else {
                return [$this->entry($path, $old, $new)];
            }
        }
        $before = $this->children($old);
        $after = $this->children($new);
        $result = [];
        foreach (array_keys($before + $after) as $key) {
            $name = (string) $key;
            if ($path === [] && !AppLogHelper::isActive($this->options, $name)) { continue; }
            $a = $before[$key] ?? $this->node(null, false);
            $b = $after[$key] ?? $this->node(null, false);
            if (!$this->different($a, $b)) { continue; }
            $nextPath = [...$path, $name];
            $callback = $path === [] ? ($this->options['functions'][$name] ?? null) : null;
            if ($callback !== null) {
                $result[] = $this->entry($nextPath, $a, $b,
                    $a['present'] ? $this->encode($callback($this->callbackValue($a))) : null,
                    $b['present'] ? $this->encode($callback($this->callbackValue($b))) : null);
            } else {
                array_push($result, ...$this->objectChanges($a, $b, $nextPath));
            }
        }
        return $result;
    }

    /** @param Node $node
     * @return array{Node, ?string}
     */
    private function project(array $node, bool $collection, bool $transform): array
    {
        if ($node['type'] !== 'array') { return [$node, null]; }
        $rawItems = $displayItems = [];
        foreach ($this->children($node) as $index => $item) {
            if ($collection && $item['type'] === 'object') {
                [$projected, $display] = $this->projectObject($item, $transform);
                $rawItems[] = $projected['raw'];
                $displayItems[] = $display;
            } elseif ($collection || AppLogHelper::isActive($this->options, (string) $index)) {
                $rawItems[] = $item['raw'];
                $callback = !$transform || $collection ? null : ($this->options['functions'][$index] ?? null);
                $displayItems[] = $callback === null ? $item['raw'] : $this->encode($callback($this->callbackValue($item)));
            }
        }
        $node['raw'] = '[' . implode(',', $rawItems) . ']';
        return [$node, '[' . implode(',', $displayItems) . ']'];
    }

    /** @param Node $node
     * @return array{Node, ?string} */
    private function projectObject(array $node, bool $transform = true): array
    {
        if ($node['type'] !== 'object') { return [$node, null]; }
        $raw = $display = [];
        foreach ($this->children($node) as $key => $member) {
            if (!AppLogHelper::isActive($this->options, (string) $key)) { continue; }
            $prefix = $this->encode((string) $key) . ':';
            $raw[] = $prefix . $member['raw'];
            $callback = $transform ? ($this->options['functions'][$key] ?? null) : null;
            $display[] = $prefix . ($callback === null ? $member['raw'] : $this->encode($callback($this->callbackValue($member))));
        }
        $node['raw'] = '{' . implode(',', $raw) . '}';
        return [$node, '{' . implode(',', $display) . '}'];
    }

    /** Exact callback input: only in-range integer JSON numbers become PHP integers.
     * @param Node $node */
    private function callbackValue(array $node): mixed
    {
        if (!$node['present'] || $node['sql_null'] || $node['type'] === 'null') { return null; }
        if ($node['type'] === 'number') {
            $integer = filter_var($node['raw'], FILTER_VALIDATE_INT);
            return $integer === false ? $node['raw'] : $integer;
        }
        if ($node['type'] === 'object' || $node['type'] === 'array') {
            $children = [];
            foreach ($this->children($node) as $key => $member) { $children[$key] = $this->callbackValue($member); }
            return $node['type'] === 'object' ? (object) $children : array_values($children);
        }
        return json_decode((string) $node['raw'], false, 512, JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $path
     * @param Node $old
     * @param Node $new */
    private function entry(array $path, array $old, array $new, ?string $oldDisplay = null, ?string $newDisplay = null): string
    {
        $labels = [];
        foreach ($path as $key) { $labels[] = $this->labelModel?->getAttributeLabel($key) ?? $key; }
        $label = $path === [] ? ($this->options['label'] ?? '') : implode(' / ', $labels);
        $extra = '';
        if ($path === [] && $this->labelModel !== null) {
            $extra = ',"labels":' . $this->encode((object) array_filter(
                $this->labelModel->attributeLabels(),
                fn ($key) => AppLogHelper::isActive($this->options, (string) $key),
                ARRAY_FILTER_USE_KEY
            ));
        }
        return '{"path":' . $this->encode($path) . ',"label":' . $this->encode($label)
            . ',"old":' . $this->side($old, $oldDisplay) . ',"new":' . $this->side($new, $newDisplay) . $extra . '}';
    }

    /** @param Node $node */
    private function side(array $node, ?string $display): string
    {
        return '{"present":' . ($node['present'] ? 'true' : 'false')
            . ',"sql_null":' . ($node['sql_null'] ? 'true' : 'false')
            . ',"value":' . ($node['raw'] ?? 'null') . ',"raw":' . $this->encode($node['raw'])
            . ($display === null ? '' : ',"display_value":' . $display) . '}';
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
