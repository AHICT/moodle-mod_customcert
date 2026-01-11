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
 * Class represents a customcert template.
 *
 * @package    mod_customcert
 * @copyright  2016 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_customcert;

use mod_customcert\event\element_created;
use mod_customcert\event\page_created;
use mod_customcert\event\page_deleted;
use mod_customcert\event\page_updated;
use mod_customcert\event\template_created;
use mod_customcert\event\template_deleted;
use mod_customcert\event\template_updated;
use mod_customcert\service\template_repository;
use mod_customcert\service\template_service;
use mod_customcert\local\preview_renderer;
use mod_customcert\service\element_factory;
use pdf;
use stdClass;

/**
 * Class represents a customcert template.
 *
 * @package    mod_customcert
 * @copyright  2016 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template {
    /**
     * @var int $id The id of the template.
     */
    protected $id;

    /**
     * @var string $name The name of this template
     */
    protected $name;

    /**
     * @var int $contextid The context id of this template
     */
    protected $contextid;

    /**
     * The constructor.
     *
     * @param stdClass $template
     */
    public function __construct($template) {
        $this->id = (int)$template->id;
        $this->name = $template->name;
        $this->contextid = (int)$template->contextid;
    }

    /**
     * Handles saving data.
     *
     * @deprecated since 5.2.0 Use \mod_customcert\service\template_service::update instead
     * @param stdClass $data the template data
     */
    public function save($data) {
        debugging('template::save() is deprecated; use template_service::update() instead.', DEBUG_DEVELOPER);
        $this->get_service()->update($this, $data);
    }

    /**
     * Handles adding another page to the template.
     *
     * @deprecated since 5.2.0 Use \mod_customcert\service\template_service::add_page instead
     * @param bool $triggertemplateupdatedevent
     * @return int the id of the page
     */
    public function add_page(bool $triggertemplateupdatedevent = true) {
        debugging('template::add_page() is deprecated; use template_service::add_page() instead.', DEBUG_DEVELOPER);
        return $this->get_service()->add_page($this, $triggertemplateupdatedevent);
    }

    /**
     * Handles saving page data.
     *
     * @deprecated since 5.2.0 Use \mod_customcert\service\template_service::save_pages instead
     * @param stdClass $data the template data
     */
    public function save_page($data) {
        debugging('template::save_page() is deprecated; use template_service::save_pages() instead.', DEBUG_DEVELOPER);
        $this->get_service()->save_pages($this, $data);
    }

    /**
     * Handles deleting the template.
     *
     * @deprecated since 5.2.0 Use \mod_customcert\service\template_service::delete instead
     * @return bool return true if the deletion was successful, false otherwise
     */
    public function delete() {
        debugging('template::delete() is deprecated; use template_service::delete() instead.', DEBUG_DEVELOPER);
        return $this->get_service()->delete($this);
    }

    /**
     * Handles deleting a page from the template.
     *
     * @deprecated since 5.2.0 Use \mod_customcert\service\template_service::delete_page instead
     * @param int $pageid the template page
     * @param bool $triggertemplateupdatedevent False if page is being deleted
     * during deletion of template.
     */
    public function delete_page(int $pageid, bool $triggertemplateupdatedevent = true): void {
        debugging('template::delete_page() is deprecated; use template_service::delete_page() instead.', DEBUG_DEVELOPER);
        $this->get_service()->delete_page($this, $pageid, $triggertemplateupdatedevent);
    }

    /**
     * Handles deleting an element from the template.
     *
     * @deprecated since 5.2.0 Use \mod_customcert\service\template_service::delete_element instead
     * @param int $elementid the template page
     */
    public function delete_element($elementid) {
        debugging('template::delete_element() is deprecated; use template_service::delete_element() instead.', DEBUG_DEVELOPER);
        $this->get_service()->delete_element($this, $elementid);
    }

    /**
     * Generate the PDF for the template.
     *
     * @param bool $preview true if it is a preview, false otherwise
     * @param int|null $userid the id of the user whose certificate we want to view
     * @param bool $return Do we want to return the contents of the PDF?
     * @return string|void Can return the PDF in string format if specified.
     */
    public function generate_pdf(bool $preview = false, ?int $userid = null, bool $return = false) {
        global $CFG, $DB, $USER;

        if (empty($userid)) {
            $user = $USER;
        } else {
            $user = \core_user::get_user($userid);
        }

        require_once($CFG->libdir . '/pdflib.php');
        require_once($CFG->dirroot . '/mod/customcert/lib.php');

        // Get the pages for the template, there should always be at least one page for each template.
        if ($pages = $DB->get_records('customcert_pages', ['templateid' => $this->id], 'sequence ASC')) {
            // Create the pdf object.
            $pdf = new pdf();

            $customcert = $DB->get_record('customcert', ['templateid' => $this->id]);

            // Apply runtime language (with shutdown failsafe) and remember original to restore later.
            $originallang = $this->apply_runtime_language_for_user($customcert, $user);

            // Configure PDF (protection, headers/footers, autopagebreak).
            $this->configure_pdf_for_customcert($pdf, $customcert);

            if (empty($customcert->deliveryoption)) {
                $deliveryoption = certificate::DELIVERY_OPTION_INLINE;
            } else {
                $deliveryoption = $customcert->deliveryoption;
            }
            // Compute final filename (mirrors legacy logic).
            $filename = $this->compute_filename_for_user($user, $customcert);

            // Set the PDF document title (for metadata, not the filename itself).
            $pdf->SetTitle($filename);

            // Loop through the pages and display their content.
            $previewrenderer = new preview_renderer();
            foreach ($pages as $page) {
                $previewrenderer->render_pdf_page((int)$page->id, $pdf, $user);
            }

            // Restore original language if we changed it.
            // Restore original language if we switched earlier.
            $this->restore_runtime_language($originallang);

            if ($return) {
                return $pdf->Output('', 'S');
            }

            $pdf->Output($filename, $deliveryoption);
        }
    }

    /**
     * Create and configure a PDF instance suitable for preview rendering.
     *
     * This helper mirrors the setup used in {@see template::generate_pdf} for preview
     * and can be used by alternate preview flows (e.g., the V2 orchestrator).
     *
     * @param stdClass $user The user that the preview is for.
     * @return pdf A configured PDF instance ready for element rendering.
     */
    public function create_preview_pdf(stdClass $user): pdf {
        global $CFG, $DB;

        require_once($CFG->libdir . '/pdflib.php');
        require_once($CFG->dirroot . '/mod/customcert/lib.php');

        $pdf = new pdf();

        $customcert = $DB->get_record('customcert', ['templateid' => $this->id]);
        // Apply language and configure pdf consistently with generate_pdf().
        $this->apply_runtime_language_for_user($customcert, $user);
        $this->configure_pdf_for_customcert($pdf, $customcert);

        return $pdf;
    }

    /**
     * Apply runtime language for this certificate/user and register a shutdown restore.
     * Returns original language if a switch occurred, null otherwise.
     *
     * @param object|null $customcert
     * @param stdClass $user
     * @return string|null
     */
    private function apply_runtime_language_for_user($customcert, stdClass $user): ?string {
        if (!$customcert) {
            return null;
        }
        $originallang = current_language();
        $uselang = mod_customcert_get_language_to_use($customcert, $user);
        $switched = mod_customcert_apply_runtime_language($uselang);
        if ($switched) {
            \core_shutdown_manager::register_function('force_current_language', [$originallang]);
            return $originallang;
        }
        return null;
    }

    /**
     * Restore runtime language if previously switched.
     *
     * @param string|null $originallang
     * @return void
     */
    private function restore_runtime_language(?string $originallang): void {
        if (!empty($originallang)) {
            mod_customcert_apply_runtime_language($originallang);
        }
    }

    /**
     * Configure a PDF for this certificate: protection, header/footer and page break.
     *
     * @param pdf $pdf
     * @param object|null $customcert
     * @return void
     */
    private function configure_pdf_for_customcert(pdf $pdf, $customcert): void {
        if ($customcert && !empty($customcert->protection)) {
            $protection = explode(', ', $customcert->protection);
            $pdf->SetProtection($protection);
        }
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(true, 0);
    }

    /**
     * Compute filename for the current user/certificate using template and pattern settings.
     * Mirrors the logic in generate_pdf(). Returns a clean filename with .pdf suffix.
     *
     * @param stdClass $user
     * @param object|null $customcert
     * @return string
     */
    public function compute_filename_for_user(stdClass $user, $customcert): string {
        global $DB;

        // Default to the template name when custom filename is disabled.
        if (empty($customcert->usecustomfilename) || empty($customcert->customfilenamepattern)) {
            $filename = rtrim(format_string($this->name, true, ['context' => $this->get_context()]), '.');
        } else {
            // Get issue record for date (if issued); fallback to current date if not found.
            $issue = $DB->get_record('customcert_issues', [
                'userid' => $user->id,
                'customcertid' => $customcert->id,
            ]);

            if ($issue && !empty($issue->timecreated)) {
                $issuedate = date('Y-m-d', $issue->timecreated);
            } else {
                $issuedate = date('Y-m-d');
            }

            $course = $DB->get_record('course', ['id' => $customcert->course]);

            $values = [
                '{FIRST_NAME}' => $user->firstname ?? '',
                '{LAST_NAME}' => $user->lastname ?? '',
                '{COURSE_SHORT_NAME}' => $course ? $course->shortname : '',
                '{COURSE_FULL_NAME}' => $course ? $course->fullname : '',
                '{ISSUE_DATE}' => $issuedate,
            ];

            // Handle group if needed.
            if ($course) {
                $groups = groups_get_all_groups($course->id, $user->id);
                if (!empty($groups)) {
                    $groupnames = array_map(
                        fn ($group) => $group->name,
                        $groups
                    );
                    $values['{GROUP_NAME}'] = implode(', ', $groupnames);
                } else {
                    $values['{GROUP_NAME}'] = '';
                }
            }

            $filename = strtr($customcert->customfilenamepattern, $values);
            $filename = rtrim($filename, '.');
        }

        // Match TCPDF filename sanitation and ensure sensible default.
        $filename = preg_replace('/[\s]+/', '_', $filename);
        $filename = preg_replace('/[^a-zA-Z0-9_\.-]/', '', $filename);
        if (empty($filename)) {
            $filename = get_string('certificate', 'customcert');
        }
        $filename = preg_replace('/\.pdf$/i', '', $filename);
        return clean_filename($filename . '.pdf');
    }

    /**
     * Handles copying this template into another.
     *
     * @param object $copytotemplate The template instance to copy to
     * @deprecated since 5.2.0 Use \mod_customcert\service\template_service::copy_to_template instead
     */
    public function copy_to_template($copytotemplate) {
        debugging('template::copy_to_template() is deprecated; use template_service::copy_to_template() instead.', DEBUG_DEVELOPER);
        $this->get_service()->copy_to_template($this, $copytotemplate);
    }

    /**
     * Handles moving an item on a template.
     *
     * @deprecated since 5.2.0 Use \mod_customcert\service\template_service::move_item instead
     * @param string $itemname the item we are moving
     * @param int $itemid the id of the item
     * @param string $direction the direction
     */
    public function move_item($itemname, $itemid, $direction) {
        debugging('template::move_item() is deprecated; use template_service::move_item() instead.', DEBUG_DEVELOPER);
        $this->get_service()->move_item($this, $itemname, $itemid, $direction);
    }

    /**
     * Returns the id of the template.
     *
     * @return int the id of the template
     */
    public function get_id() {
        return (int)$this->id;
    }

    /**
     * Returns the name of the template.
     *
     * @return string the name of the template
     */
    public function get_name() {
        return $this->name;
    }

    /**
     * Returns the context id.
     *
     * @return int the context id
     */
    public function get_contextid() {
        return $this->contextid;
    }

    /**
     * Returns the context id.
     *
     * @return \context the context
     */
    public function get_context() {
        return \context::instance_by_id($this->contextid);
    }

    /**
     * Returns the context id.
     *
     * @return \context_module|null the context module, null if there is none
     */
    public function get_cm() {
        $context = $this->get_context();
        if ($context->contextlevel === CONTEXT_MODULE) {
            return get_coursemodule_from_id('customcert', $context->instanceid, 0, false, MUST_EXIST);
        }

        return null;
    }

    /**
     * Ensures the user has the proper capabilities to manage this template.
     *
     * @throws \required_capability_exception if the user does not have the necessary capabilities (ie. Fred)
     */
    public function require_manage() {
        require_capability('mod/customcert:manage', $this->get_context());
    }

    /**
     * Creates a template.
     *
     * @param string $templatename the name of the template
     * @param int $contextid the context id
     * @return template the template object
     */
    public static function create($templatename, $contextid) {
        $repository = new template_repository();
        $id = $repository->create((object) ['name' => $templatename, 'contextid' => $contextid]);
        $record = $repository->get_by_id_or_fail($id);

        $template = new template($record);

        template_created::create_from_template($template)->trigger();

        return $template;
    }

    /**
     * Lazily build a template_service instance.
     *
     * @return template_service
     */
    private function get_service(): template_service {
        return new template_service();
    }

    /**
     * Checks if a page has been updated given form information
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
