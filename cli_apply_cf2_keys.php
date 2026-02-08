<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * CLI script to apply CF2 answer keys.
 *
 * @package    local_hlai_grading
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');

/**
 * Normalize text by stripping HTML tags and collapsing whitespace.
 *
 * @param string $text The text to normalize.
 * @return string The normalized text.
 */
function normalize_text_local(string $text): string {
    $plain = trim(strip_tags($text));
    if ($plain === '') {
        return '';
    }
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5);
    $plain = preg_replace('/\s+/u', ' ', $plain);
    return trim($plain);
}

global $DB;

$course = $DB->get_record('course', ['fullname' => 'Ranged Rubric Course'], 'id, fullname', IGNORE_MISSING);
if (!$course) {
    echo "Course not found: Ranged Rubric Course\n";
    exit(1);
}

$quizname = 'ASSESSMENT TITLE: CF2 Theory Assessment V 1';
$quiz = $DB->get_record('quiz', ['course' => $course->id, 'name' => $quizname], 'id, name', IGNORE_MISSING);
if (!$quiz) {
    echo "Quiz not found: {$quizname}\n";
    exit(1);
}

echo "Course: {$course->fullname} (ID {$course->id})\n";
echo "Quiz: {$quiz->name} (ID {$quiz->id})\n";

$keymap = [
    [
        'match' => 'gnocchi parisienne',
        'answer' => "Start by making a choux pastry. Pipe the pastry onto a flour-dusted surface, " .
            "cut into small pieces, and then cook them in boiling water.",
    ],
    [
        'match' => 'washed thoroughly before use',
        'answer' => "Any of the below:\n- To remove dirt.\n- To remove bacteria on the surface " .
            "of the vegetable.\n- To remove insects that may be present.",
    ],
    [
        'match' => 'rice pilaf',
        'answer' => "Rice pilaf is typically braised and finished by simmering on the stovetop or baking in the oven.",
    ],
    [
        'match' => 'prepare rice before cooking',
        'answer' => "Rice may be washed before cooking. Washing removes loose starch from the grains " .
            "and helps prevent the rice from sticking together.",
    ],
    [
        'match' => 'nutritional value of pasta',
        'answer' => "Pasta is nutritious; its complex carbohydrates provide energy similar to protein " .
            "while being lower in calories and fat.",
    ],
    [
        'match' => 'uses of farinaceous',
        'answer' => "Uses on the menu include:\n- Garnish.\n- Entrees and main dishes.\n- Accompaniments to main courses.",
    ],
    [
        'match' => 'difference in ingredients and preparation between dry pasta and fresh pasta',
        'answer' => "Fresh pasta is typically made by hand, often contains eggs, and uses OO or " .
            "all-purpose flour. Dry pasta is mostly machine-made, usually uses water only, " .
            "and uses hard or high-protein flour.",
    ],
    [
        'match' => 'portion size of pasta',
        'answer' => "Factors that may affect portion size include:\n" .
            "- Type of guest and expectations.\n" .
            "- Served as a main course or appetizer.\n" .
            "- Time of the day.\n- Size of the menu.",
    ],
    [
        'match' => 'product yield is affected',
        'answer' => "Leg lamb has a large bone which reduces edible yield and makes portioning " .
            "harder, lowering yield. Fresh fish has low yield due to waste " .
            "(intestine, bones, skin) and requires skill to fillet.",
    ],
    [
        'match' => 'correct cooking technique',
        'answer' => "Steps:\n- Clean and cut the vegetable to the correct size.\n" .
            "- Boil a large pot of salted water (as salty as the sea).\n" .
            "- Cook for 2 to 7 minutes until al dente, depending on size.\n" .
            "- Transfer to ice-cold water to stop cooking and preserve color.\n" .
            "- Remove and store covered in a cool, dry place until ready to use.",
    ],
    [
        'match' => 'sub classifications for root vegetables',
        'answer' => "Roots (beetroot, carrot, radish, turnip), bulbs (garlic, onion, leek), " .
            "and tubers (potato, sweet potato, Jerusalem artichoke).",
    ],
    [
        'match' => 'green asparagus',
        'answer' => "Store green asparagus wrapped or on a damp cloth. " .
            "Cook by sauteing in oil or butter to retain water-soluble vitamins.",
    ],
    [
        'match' => 'aim of cooking vegetables',
        'answer' => "Soften fibers with minimal water absorption, minimize nutrient loss, " .
            "maintain palatability, make starch more digestible, and preserve " .
            "natural colors and textures.",
    ],
    [
        'match' => 'best preparation for these tomatoes',
        'answer' => "Fresh, whole, served at room temperature.",
    ],
    [
        'match' => 'heirloom tomatoes',
        'answer' => "Old breed, very sweet and tasty, unique shapes.",
    ],
    [
        'match' => 'yield tests',
        'answer' => "To establish the real cost of usable portions compared to whole vegetables, " .
            "and to determine the whole weight needed to buy for sufficient usable portions.",
    ],
    [
        'match' => 'prepare a fresh pasta dough',
        'answer' => "Mix flour, eggs, and salt into a consistent ball, rest the dough in the cooler " .
            "to relax gluten, then roll out by hand or pasta machine and cut into shape.",
    ],
];

$slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot ASC');
if (!$slots) {
    echo "No slots found for quiz.\n";
    exit(1);
}

$updated = 0;
$matched = [];
$unmatched = [];

foreach ($slots as $slot) {
    $ref = $DB->get_record('question_references', [
        'component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => $slot->id,
    ], '*', IGNORE_MISSING);

    if (!$ref) {
        continue;
    }

    $version = $DB->get_record_sql(
        'SELECT * FROM {question_versions} WHERE questionbankentryid = ? ORDER BY version DESC',
        [$ref->questionbankentryid],
        IGNORE_MULTIPLE
    );
    if (!$version) {
        continue;
    }

    $question = $DB->get_record(
        'question',
        ['id' => $version->questionid],
        'id, name, qtype, questiontext, questiontextformat',
        IGNORE_MISSING
    );
    if (!$question || $question->qtype !== 'essay') {
        continue;
    }

    $text = format_text((string)$question->questiontext, (int)$question->questiontextformat);
    $text = normalize_text_local($text);
    $textlower = core_text::strtolower($text);

    $found = null;
    foreach ($keymap as $entry) {
        if (strpos($textlower, $entry['match']) !== false) {
            $found = $entry;
            break;
        }
    }

    if (!$found) {
        $unmatched[] = (object)[
            'slot' => $slot->slot, 'id' => $question->id, 'name' => $question->name, 'text' => $text,
        ];
        continue;
    }

    $options = $DB->get_record('qtype_essay_options', ['questionid' => $question->id], '*', IGNORE_MISSING);
    if (!$options) {
        continue;
    }

    $options->graderinfo = $found['answer'];
    $options->graderinfoformat = FORMAT_HTML;
    $DB->update_record('qtype_essay_options', $options);

    $matched[] = $question->id;
    $updated++;
}

echo "Updated essay questions: {$updated}\n";
if (!empty($unmatched)) {
    echo "Unmatched essay questions:\n";
    foreach ($unmatched as $item) {
        echo "- Slot {$item->slot} | QID {$item->id} | {$item->name}\n";
        echo "  {$item->text}\n";
    }
}

$pending = $DB->get_records('local_hlai_grading_queue', ['status' => 'pending', 'component' => 'mod_quiz']);
$payloadupdated = 0;
foreach ($pending as $item) {
    $payload = json_decode($item->payload ?? '', true);
    if (!$payload || ($payload['modulename'] ?? '') !== 'quiz') {
        continue;
    }
    if (!empty($payload['graderkey'])) {
        continue;
    }
    $questionid = $payload['questionid'] ?? null;
    if (!$questionid) {
        continue;
    }
    $options = $DB->get_record('qtype_essay_options', ['questionid' => $questionid], 'graderinfo,graderinfoformat', IGNORE_MISSING);
    if (!$options || trim((string)$options->graderinfo) === '') {
        continue;
    }
    $formatted = format_text((string)$options->graderinfo, (int)($options->graderinfoformat ?? FORMAT_HTML));
    $graderkey = normalize_text_local($formatted);
    if ($graderkey === '') {
        continue;
    }
    $payload['graderkey'] = $graderkey;
    $item->payload = json_encode($payload);
    $DB->update_record('local_hlai_grading_queue', $item);
    $payloadupdated++;
}

echo "Updated pending queue items with keys: {$payloadupdated}\n";
