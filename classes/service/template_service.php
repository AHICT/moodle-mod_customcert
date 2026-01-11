<?php
// This file is part of the customcert module for Moodle - http://moodle.org/
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

declare(strict_types=1);

namespace mod_customcert\service;

use context;
use dml_exception;
use invalid_parameter_exception;
use mod_customcert\element\element_bootstrap;
use mod_customcert\service\element_factory;
use mod_customcert\service\element_registry;
use mod_customcert\service\page_update;
use mod_customcert\event\element_created;
use mod_customcert\event\page_created;
use mod_customcert\event\page_deleted;
use mod_customcert\event\page_updated;
use mod_customcert\event\template_deleted;
use mod_customcert\event\template_updated;
use mod_customcert\template;
use stdClass;

/**
 * Service for template-level operations (pages/elements) with transactional boundaries.
 *
 * @package    mod_customcert
 * @copyright  2026 Mark Nelson <mdjnelson@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_service {
    /** @var template_repository */
    private template_repository $templates;
    /** @var page_repository */
    private page_repository $pages;
    /** @var element_repository */
    private element_repository $elements;

    /**
     * template_service constructor.
     *
     * @param template_repository|null $templates
     * @param page_repository|null $pages
     * @param element_repository|null $elements
     */
    public function __construct(
        ?template_repository $templates = null,
        ?page_repository $pages = null,
        ?element_repository $elements = null,
    ) {
        $this->templates = $templates ?? new template_repository();
        $this->pages = $pages ?? new page_repository();
        $this->elements = $elements ?? $this->build_element_repository();
    }

    /**
     * Build the element repository with default registry/factory wiring.
     *
     * @return element_repository
     */
    private function build_element_repository(): element_repository {
        $registry = new element_registry();
        element_bootstrap::register_defaults($registry);
        $factory = new element_factory($registry);
        return new element_repository($factory);
    }

    /**
     * Update template metadata (name) and fire template_updated if changed.
     *
     * @param template $template
     * @param stdClass $data
     * @return void
     * @throws invalid_parameter_exception
     * @throws dml_exception
     */
    public function update(template $template, stdClass $data): void {
        $newname = $data->name ?? '';
        $changed = $template->get_name() !== $newname;
        $this->templates->update($template->get_id(), (object) ['name' => $newname]);

        if ($changed) {
            template_updated::create_from_template($template)->trigger();
        }
    }

    /**
     * Add a page to a template with default dimensions and fire events.
     *
     * @param template $template
     * @param bool $triggertemplateupdatedevent
     * @return int
     * @throws dml_exception
     */
    public function add_page(template $template, bool $triggertemplateupdatedevent = true): int {
        $now = time();
        $pageid = $this->pages->create((object) [
            'templateid' => $template->get_id(),
            'width' => 210,
            'height' => 297,
            'leftmargin' => 0,
            'rightmargin' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $page = $this->pages->get_by_id_or_fail($pageid);
        page_created::create_from_page($page, $template)->trigger();

        if ($triggertemplateupdatedevent) {
            template_updated::create_from_template($template)->trigger();
        }

        return $pageid;
    }

    /**
     * Save page size/margins for all pages on a template.
     *
     * Note: caller is expected to fire template_updated() afterwards.
     *
     * @param template $template
     * @param stdClass $data
     * @return void
     * @throws dml_exception
     */
    public function save_pages(template $template, stdClass $data): void {
        $pages = $this->pages->list_by_template($template->get_id());
        if (empty($pages)) {
            return;
        }

        $time = time();
        foreach ($pages as $page) {
            if ($this->has_page_been_updated($page, $data)) {
                $width = 'pagewidth_' . $page->id;
                $height = 'pageheight_' . $page->id;
                $leftmargin = 'pageleftmargin_' . $page->id;
                $rightmargin = 'pagerightmargin_' . $page->id;

                $update = (object) [
                    'id' => $page->id,
                    'width' => $data->$width,
                    'height' => $data->$height,
                    'leftmargin' => $data->$leftmargin,
                    'rightmargin' => $data->$rightmargin,
                    'timemodified' => $time,
                ];

                $this->pages->update(
                    (int)$page->id,
                    new page_update(
                        (int)$data->$width,
                        (int)$data->$height,
                        (int)$data->$leftmargin,
                        (int)$data->$rightmargin,
                        $time
                    )
                );
                page_updated::create_from_page($update, $template)->trigger();
            }
        }
    }

    /**
     * Delete a template and all its pages/elements.
     *
     * @param template $template
     * @return bool
     * @throws dml_exception
     */
    public function delete(template $template): bool {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $pages = $this->pages->list_by_template($template->get_id());
        foreach ($pages as $page) {
            $this->delete_page($template, (int)$page->id, false);
        }

        $this->templates->delete($template->get_id());

        $transaction->allow_commit();

        template_deleted::create_from_template($template)->trigger();
        return true;
    }

    /**
     * Delete a page and its elements, resequencing remaining pages.
     *
     * @param template $template
     * @param int $pageid
     * @param bool $triggertemplateupdatedevent
     * @return void
     * @throws dml_exception
     */
    public function delete_page(template $template, int $pageid, bool $triggertemplateupdatedevent = true): void {
        global $DB;

        $page = $this->pages->get_by_id_or_fail($pageid);

        // Defensive: ensure the page belongs to this template.
        if ((int)$page->templateid !== $template->get_id()) {
            throw new invalid_parameter_exception('Page does not belong to template');
        }

        if ($elements = $DB->get_records('customcert_elements', ['pageid' => $page->id])) {
            foreach ($elements as $element) {
                if ($e = element_factory::get_element_instance($element)) {
                    $e->delete();
                } else {
                    $DB->delete_records('customcert_elements', ['id' => $element->id]);
                }
            }
        }

        $DB->delete_records('customcert_pages', ['id' => $page->id]);

        page_deleted::create_from_page($page, $template)->trigger();

        // Resequence remaining pages.
        $this->pages->resequence($template->get_id());

        if ($triggertemplateupdatedevent) {
            template_updated::create_from_template($template)->trigger();
        }
    }

    /**
     * Delete an element and resequence remaining elements on the page.
     *
     * @param template $template
     * @param int $elementid
     * @return void
     * @throws dml_exception
     */
    public function delete_element(template $template, int $elementid): void {
        global $DB;

        $element = $this->elements->get_by_id_or_fail($elementid);

        if ($e = element_factory::get_element_instance($element)) {
            $e->delete();
        } else {
            $DB->delete_records('customcert_elements', ['id' => $elementid]);
        }

        // Resequence remaining elements.
        $sql = "UPDATE {customcert_elements}
                   SET sequence = sequence - 1
                 WHERE pageid = :pageid
                   AND sequence > :sequence";
        $DB->execute($sql, ['pageid' => $element->pageid, 'sequence' => $element->sequence]);

        template_updated::create_from_template($template)->trigger();
    }

    /**
     * Copy all pages/elements from this template into another template.
     *
     * @param template $source
     * @param template $target
     * @return void
     * @throws dml_exception
     */
    public function copy_to_template(template $source, template $target): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $sourcepages = $this->pages->list_by_template($source->get_id());
        foreach ($sourcepages as $sourcepage) {
            $newpageid = $this->pages->create((object) [
                'templateid' => $target->get_id(),
                'width' => $sourcepage->width,
                'height' => $sourcepage->height,
                'leftmargin' => $sourcepage->leftmargin,
                'rightmargin' => $sourcepage->rightmargin,
                'sequence' => $sourcepage->sequence,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);

            $newpage = $this->pages->get_by_id_or_fail($newpageid);
            page_created::create_from_page($newpage, $target)->trigger();

            if ($templateelements = $DB->get_records('customcert_elements', ['pageid' => $sourcepage->id])) {
                foreach ($templateelements as $templateelement) {
                    $element = clone($templateelement);
                    $element->pageid = $newpage->id;
                    $element->timecreated = time();
                    $element->timemodified = $element->timecreated;
                    $element->id = $DB->insert_record('customcert_elements', $element);

                    if ($e = element_factory::get_element_instance($element)) {
                        if (method_exists($e, 'copy_element') && !$e->copy_element($templateelement)) {
                            $e->delete();
                        } else {
                            element_created::create_from_element($e)->trigger();
                        }
                    }
                }
            }
        }

        $transaction->allow_commit();

        // Trigger event if copying into a course module template; system-level copy is handled elsewhere.
        if ($target->get_context()->contextlevel !== CONTEXT_SYSTEM) {
            template_updated::create_from_template($target)->trigger();
        }
    }

    /**
     * Move a page or element up/down by swapping sequences and fire template_updated.
     *
     * @param template $template
     * @param string $itemname 'page' or 'element'
     * @param int $itemid
     * @param string $direction 'up' or 'down'
     * @return void
     * @throws dml_exception
     */
    public function move_item(template $template, string $itemname, int $itemid, string $direction): void {
        global $DB;

        $table = $itemname === 'page' ? 'customcert_pages' : 'customcert_elements';

        $moveitem = $DB->get_record($table, ['id' => $itemid]);
        if (!$moveitem) {
            return;
        }

        $sequence = $direction === 'up' ? $moveitem->sequence - 1 : $moveitem->sequence + 1;

        $params = $itemname === 'page' ? ['templateid' => $moveitem->templateid] : ['pageid' => $moveitem->pageid];
        $swapitem = $DB->get_record($table, $params + ['sequence' => $sequence]);

        if ($swapitem) {
            $DB->set_field($table, 'sequence', $swapitem->sequence, ['id' => $moveitem->id]);
            $DB->set_field($table, 'sequence', $moveitem->sequence, ['id' => $swapitem->id]);
            template_updated::create_from_template($template)->trigger();
        }
    }

    /**
     * Determine if a page has been updated based on form data.
     *
     * @param stdClass $page
     * @param stdClass $formdata
     * @return bool
     */
    private function has_page_been_updated($page, $formdata): bool {
        $width = 'pagewidth_' . $page->id;
        $height = 'pageheight_' . $page->id;
        $leftmargin = 'pageleftmargin_' . $page->id;
        $rightmargin = 'pagerightmargin_' . $page->id;

        if ($page->width != $formdata->$width) {
            return true;
        }

        if ($page->height != $formdata->$height) {
            return true;
        }

        if ($page->leftmargin != $formdata->$leftmargin) {
            return true;
        }

        if ($page->rightmargin != $formdata->$rightmargin) {
            return true;
        }

        return false;
    }
}
