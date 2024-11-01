<?php

class Sikshya_Core_Lesson
{
    private $all_lesson_quiz_ids = array();


    public function save($lesson_ids = array(), $section_id = 0, $lesson_quiz_order = array())
    {

        $updated_lesson_ids = array();

        foreach ($lesson_ids as $lesson_id) {

            $lesson_id = absint($lesson_id);

            if (SIKSHYA_LESSONS_CUSTOM_POST_TYPE === get_post_type($lesson_id) && $section_id > 0) {

                update_post_meta($lesson_id, 'section_id', $section_id);

                $order_number = isset($lesson_quiz_order[$lesson_id]) ? absint($lesson_quiz_order[$lesson_id]) : 0;

                update_post_meta($lesson_id, 'sikshya_order_number', $order_number);

                $updated_lesson_ids[] = $lesson_id;
            }


        }

        return $updated_lesson_ids;

    }


    public function get_all_by_section($section_id)
    {
        if ($section_id instanceof \WP_Post) {
            $section_id = $section_id->ID;
        }

        $args = array(
            'numberposts' => -1,
            'no_found_rows' => true,
            'orderby' => 'menu_order',
            'order' => 'asc',
            'post_type' => SIKSHYA_LESSONS_CUSTOM_POST_TYPE,
            'meta_query' => array(
                array(
                    'key' => 'section_id',
                    'value' => (int)$section_id
                )
            )
        );
        $data = get_posts($args);

        return $data;
    }

    public function get_all_by_course($course_id)
    {
        if ($course_id instanceof \WP_Post) {
            $course_id = $course_id->ID;
        }

        $args = array(
            'numberposts' => -1,
            'no_found_rows' => true,
            'orderby' => 'menu_order',
            'order' => 'asc',
            'post_type' => SIKSHYA_LESSONS_CUSTOM_POST_TYPE,
            'meta_query' => array(
                array(
                    'key' => 'course_id',
                    'value' => (int)$course_id,
                )
            )
        );
        $data = get_posts($args);

        return $data;
    }

    function render_tmpl($id, $name, $title, $content, $hasQuiz = false, $quizesHtml = '')
    {
        ob_start();

        include SIKSHYA_PATH . '/includes/meta-boxes/views/tmpl/lesson.php';

        return ob_get_clean();
    }

    public function get_child_count_text($lesson_id)
    {
        if (sikshya_is_new_post($lesson_id)) {
            return '';
        }
        $lesson_id = absint($lesson_id);
        if ($lesson_id < 1) {
            return '';
        }
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT COUNT(*) as total, p.post_type           
FROM $wpdb->posts p
INNER JOIN $wpdb->postmeta pm
ON p.ID=pm.post_id
WHERE pm.meta_key = 'lesson_id' 
AND pm.meta_value = %d  and p.post_status='publish'
GROUP BY p.post_type having p.post_type in (%s,%s) ORDER BY FIELD (p.post_type, %s, %s)",
            $lesson_id,
            SIKSHYA_QUIZZES_CUSTOM_POST_TYPE,
            SIKSHYA_QUESTIONS_CUSTOM_POST_TYPE,
            SIKSHYA_QUIZZES_CUSTOM_POST_TYPE,
            SIKSHYA_QUESTIONS_CUSTOM_POST_TYPE
        );

        $results = $wpdb->get_results($sql);

        $count_string = '';

        foreach ($results as $result) {

            $total = isset($result->total) ? $result->total : 0;

            $post_type = isset($result->post_type) ? $result->post_type : '';

            switch ($post_type) {

                case SIKSHYA_QUIZZES_CUSTOM_POST_TYPE:
                    $count_string .= $total . ' Quiz';
                    break;
                case SIKSHYA_QUESTIONS_CUSTOM_POST_TYPE:
                    $count_string .= ', ' . $total . ' Question';
                    break;
            }

        }


        echo !empty($count_string) ? '( ' . $count_string . ' )' : '';
    }

    public function remove_from_section($lesson_id = 0, $section_id = 0)
    {
        if ($section_id < 1 || $section_id < 1) {
            return false;
        }
        return delete_post_meta($lesson_id, 'section_id', $section_id);
    }

    public function remove_from_course($lesson_id = 0, $course_id = 0)
    {
        if ($lesson_id < 1 || $course_id < 1) {
            return false;
        }
        return delete_post_meta($lesson_id, 'course_id', $course_id);
    }

    public function make_complete($user_id = 0, $lesson_id = 0, $course_id = 0, $order_item_id = 0)
    {
        $user_id = $user_id < 1 ? get_current_user_id() : $user_id;
        if ($user_id < 1 || $lesson_id < 1 || $order_item_id < 1 || $course_id < 1) {

            return false;
        }
        global $wpdb;
        $sql = $wpdb->prepare(
            "INSERT INTO " . SIKSHYA_DB_PREFIX . "user_items 
            (user_id, item_id, start_time, start_time_gmt, end_time,end_time_gmt, item_type, status,reference_id,reference_type,parent_id) 
            values
            (%d, %d, %s, %s, %s, %s, %s, %s, %d, %s, %d)",
            $user_id,
            $lesson_id,
            current_time('mysql'),
            current_time('mysql', true),
            current_time('mysql'),
            current_time('mysql', true),
            SIKSHYA_LESSONS_CUSTOM_POST_TYPE,
            'completed',
            $course_id,
            SIKSHYA_COURSES_CUSTOM_POST_TYPE,
            $order_item_id


        );

        update_user_meta($user_id, 'sikshya_last_completed_item_id', $lesson_id);

        $all_lesson_quiz_ids = sikshya()->course->get_lesson_quiz_ids();

        $next_lesson_quiz_id = $this->get_next_params($all_lesson_quiz_ids, true);

        update_user_meta($user_id, 'sikshya_next_item_id', $next_lesson_quiz_id);

        return $wpdb->query($sql);
    }

    public function is_completed($lesson_id = 0, $user_id = 0, $post_type = SIKSHYA_LESSONS_CUSTOM_POST_TYPE)
    {

        $user_id = $user_id < 1 ? get_current_user_id() : $user_id;

        if ($user_id < 1 || $lesson_id < 1) {

            return false;
        }
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT * FROM " . SIKSHYA_DB_PREFIX . "user_items WHERE user_id= %d and item_id=%d and status=%s and item_type=%s",
            $user_id,
            $lesson_id,
            'completed',
            $post_type
        );

        $results = $wpdb->get_results($sql);

        return count($results) > 0 ? true : false;
    }


    public function get_prev_params($all_lesson_quiz_ids = array())
    {

        $id = sikshya_lesson_quiz_id();

        $prev = 0;

        if (count($all_lesson_quiz_ids) > 0) {
            if (0 < ($at = array_search($id, $all_lesson_quiz_ids))) {
                $prev = $all_lesson_quiz_ids[$at - 1];
            }
        }

        if ($prev > 0) {
            return array(
                'prev_link' => get_permalink($prev),
                'title' => get_the_title($prev)
            );
        }
        return array();
    }

    public function get_next_params($all_lesson_quiz_ids = array(), $id_only = false)
    {
        $id = sikshya_lesson_quiz_id();

        $next = 0;

        if (count($all_lesson_quiz_ids) > 0) {
            if (sizeof($all_lesson_quiz_ids) - 1 > ($at = array_search($id, $all_lesson_quiz_ids))) {
                $next = $all_lesson_quiz_ids[$at + 1];
            }
        }
        if ($id_only) {
            return $next;
        }
        if ($next > 0) {


            return array(
                'next_link' => get_permalink($next),
                'title' => get_the_title($next)
            );
        }
        return array();
    }


    public function get_prev_question()
    {

        $id = get_the_ID();

        $quiz_id = get_post_meta($id, 'quiz_id', true);
        $prev = false;
        if (($questions = $this->get_all_by_quiz($quiz_id))) {
            $question_ids = wp_list_pluck($questions, 'ID');
            if (0 < ($at = array_search($id, $question_ids))) {
                $prev = $question_ids[$at - 1];
            }
        }

        return apply_filters('sikshya_quiz_prev_question_id', $prev, $id);
    }


    public function get_next_question($id)
    {
        $quiz_id = get_post_meta($id, 'quiz_id', true);
        $next = false;
        if (($questions = $this->get_all_by_quiz($quiz_id))) {
            $question_ids = wp_list_pluck($questions, 'ID');
            if (sizeof($question_ids) - 1 > ($at = array_search($id, $question_ids))) {
                $next = $question_ids[$at + 1];
            }
        }

        return apply_filters('sikshya_quiz_next_question_id', $next, $id);
    }

    public function add_lesson($lesson_title)
    {
        if ('' == $lesson_title) {

            return null;
        }
        $args = array(
            'post_title' => $lesson_title,
            'post_content' => '',
            'post_status' => 'publish',
            'post_type' => SIKSHYA_LESSONS_CUSTOM_POST_TYPE,
        );
        $lesson_id = wp_insert_post($args);

        return array('id' => $lesson_id, 'title' => $lesson_title, 'type' => 'lesson_ids');

    }

    public function get_lesson_meta($lesson_id = null)
    {

        if ($lesson_id instanceof \WP_Post && !is_null($lesson_id)) {
            $lesson_id = $lesson_id->ID;
        } else {
            global $post;
            $lesson_id = isset($post->ID) ? $post->ID : 0;
        }

        $data = array(
            'sikshya_lesson_duration' => get_post_meta($lesson_id, 'sikshya_lesson_duration', true),
            'sikshya_lesson_duration_time' => get_post_meta($lesson_id, 'sikshya_lesson_duration_time', true),
            'sikshya_is_preview_lesson' => get_post_meta($lesson_id, 'sikshya_is_preview_lesson', true),
            'sikshya_lesson_video_source' => get_post_meta($lesson_id, 'sikshya_lesson_video_source', true),
            'sikshya_lesson_youtube_video_url' => get_post_meta($lesson_id, 'sikshya_lesson_youtube_video_url', true),
        );

        return $data;

    }

    public function count_total_from_section_id($section_id)
    {
        $all_data = $this->get_all_by_section($section_id);
        if (is_array($all_data)) {
            return count($all_data);
        }
        return 0;
    }

    public function get_last_started_lesson_quiz_id($current_user_id)
    {
    }


}