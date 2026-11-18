<?php
declare(strict_types=1);
namespace Example;
use Ktulchu\Yii2AppLog\History;
use Ktulchu\Yii2AppLog\RawJson;
use yii\console\Controller;
final class SmokeController extends Controller
{
    public function actionIndex(): int
    {
        $db = Note::getDb();
        $transaction = $db->beginTransaction();
        try {
            $note = new Note();
            $note->setAttribute('title', 'first');
            $note->setAttribute('document', RawJson::value('{"n":123456789012345678901234567890,"empty":{}}'));
            $note->setAttribute('settings', RawJson::value('{"b":2,"a":1}', true));
            if (!$note->save(false)) { throw new \RuntimeException('Insert failed.'); }
            $note->getBehavior('audit')->sourceType = 'queue:ExamplePaymentJob';
            $note->setAttribute('title', 'second');
            $note->setAttribute('settings', RawJson::value('{"a":1.0,"b":2}', true));
            if (!$note->save(false)) { throw new \RuntimeException('Update failed.'); }
            $note->getBehavior('audit')->sourceType = null;
            $note->refresh();
            $note->save(false);
            $note->setAttribute('is_deleted', true);
            if (!$note->save(false)) { throw new \RuntimeException('Soft delete failed.'); }
            $note->setAttribute('is_deleted', false);
            if (!$note->save(false)) { throw new \RuntimeException('Restore failed.'); }
            if ($note->delete() !== 1) { throw new \RuntimeException('Delete failed.'); }
            $rows = History::read($db, Note::class, $note->getPrimaryKey(true));
            if (array_column($rows, 'operation') !== ['insert', 'update', 'delete', 'update', 'delete']) {
                throw new \RuntimeException('Incorrect CRUD audit history.');
            }
            $consoleSource = \Yii::$app->requestedRoute
                ? 'console:' . \Yii::$app->requestedRoute : 'console';
            if (array_column($rows, 'source_type') !== [
                $consoleSource, 'queue:ExamplePaymentJob', $consoleSource, $consoleSource, $consoleSource,
            ]) {
                throw new \RuntimeException('Console route / job source audit failed.');
            }
            if (array_keys(json_decode($rows[1]['new_values'], true)) !== ['title']) {
                throw new \RuntimeException('JSONB semantic equality failed.');
            }
            if (!str_contains($rows[0]['new_values'], '123456789012345678901234567890')) {
                throw new \RuntimeException('Numeric precision was lost.');
            }
            if (!$rows[2]['is_soft_delete'] || $rows[4]['is_soft_delete']
                || array_column($rows, 'staff_id') !== [7, 7, 7, 7, 7]
                || History::readLogical($db, 'ExampleDocument', ['id' => $note->getAttribute('id')]) !== $rows) {
                throw new \RuntimeException('Actor, soft delete or logical identity failed.');
            }
            $document = json_decode($rows[0]['new_values'], true, 512, JSON_BIGINT_AS_STRING);
            if (!isset($document['document']['changes'])) { throw new \RuntimeException('JSON presentation missing.'); }
            $transaction->commit();
            $this->stdout("Independent Composer application: migration + CRUD + JSON/JSONB + presentation + actors + source_type + logical identity + soft delete passed.\n");
            return 0;
        } catch (\Throwable $e) {
            if ($transaction->isActive) { $transaction->rollBack(); }
            throw $e;
        }
    }
}
