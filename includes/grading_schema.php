<?php
function ensureGradingSchema($conn)
{
    $columns = $conn->query("SHOW COLUMNS FROM assessments LIKE 'activity_status'");
    if ($columns->num_rows === 0) {
        try {
            $conn->query("ALTER TABLE assessments ADD COLUMN activity_status ENUM('Active','Archived') NOT NULL DEFAULT 'Active'");
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() !== 1060) {
                throw $e;
            }
        }
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS grading_term_submissions (
            submission_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            class_subject_id INT NOT NULL,
            grading_period TINYINT UNSIGNED NOT NULL,
            teacher_id INT NOT NULL,
            status ENUM('Submitted','Finalized','Returned') NOT NULL DEFAULT 'Submitted',
            submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finalized_at TIMESTAMP NULL DEFAULT NULL,
            finalized_by_user_id INT NULL,
            INDEX idx_term_submission_lookup (class_subject_id, grading_period, submission_id),
            INDEX idx_term_submission_status (status, submitted_at),
            CONSTRAINT fk_term_submission_class FOREIGN KEY (class_subject_id) REFERENCES class_subjects(class_subject_id),
            CONSTRAINT fk_term_submission_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id),
            CONSTRAINT fk_term_submission_finalizer FOREIGN KEY (finalized_by_user_id) REFERENCES users(user_id)
        )"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS student_term_grade_records (
            term_grade_record_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            submission_id BIGINT UNSIGNED NOT NULL,
            student_id INT NOT NULL,
            initial_grade DECIMAL(6,2) NOT NULL,
            transmuted_grade DECIMAL(6,2) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_term_grade_student (submission_id, student_id),
            INDEX idx_term_grade_student (student_id, submission_id),
            CONSTRAINT fk_term_grade_submission FOREIGN KEY (submission_id) REFERENCES grading_term_submissions(submission_id),
            CONSTRAINT fk_term_grade_student FOREIGN KEY (student_id) REFERENCES students(student_id)
        )"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS student_term_component_records (
            submission_id BIGINT UNSIGNED NOT NULL,
            student_id INT NOT NULL,
            component_id INT NOT NULL,
            component_name_snapshot VARCHAR(100) NOT NULL,
            weight_percentage DECIMAL(5,2) NOT NULL,
            percentage_score DECIMAL(6,2) NOT NULL,
            weighted_contribution DECIMAL(6,2) NOT NULL,
            PRIMARY KEY (submission_id, student_id, component_id),
            CONSTRAINT fk_term_component_submission FOREIGN KEY (submission_id) REFERENCES grading_term_submissions(submission_id),
            CONSTRAINT fk_term_component_student FOREIGN KEY (student_id) REFERENCES students(student_id)
        )"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS student_score_audit (
            audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            score_id INT NOT NULL,
            assessment_id INT NOT NULL,
            student_id INT NOT NULL,
            teacher_id INT NOT NULL,
            previous_score DECIMAL(8,2) NULL,
            new_score DECIMAL(8,2) NOT NULL,
            changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_score_audit_student (student_id, changed_at),
            INDEX idx_score_audit_assessment (assessment_id, changed_at)
        )"
    );
}

function getGradingTermData($conn, $classSubjectId, $gradingPeriod)
{
    $componentQuery = $conn->prepare(
        "SELECT component_id, component_name, weight_percentage
         FROM grading_components WHERE class_subject_id = ? ORDER BY component_id"
    );
    $componentQuery->bind_param("i", $classSubjectId);
    $componentQuery->execute();
    $components = $componentQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $componentQuery->close();

    $assessmentQuery = $conn->prepare(
        "SELECT assessments.assessment_id, assessments.component_id, assessments.assessment_name,
                assessments.assessment_type, assessments.max_score, assessments.assessment_date,
                assessments.activity_status, grading_components.component_name,
                grading_components.weight_percentage
         FROM assessments
         JOIN grading_components ON grading_components.component_id = assessments.component_id
         WHERE assessments.class_subject_id = ? AND assessments.grading_period = ? AND assessments.activity_status = 'Active'
         ORDER BY assessments.assessment_date, assessments.assessment_id"
    );
    $assessmentQuery->bind_param("ii", $classSubjectId, $gradingPeriod);
    $assessmentQuery->execute();
    $assessments = $assessmentQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $assessmentQuery->close();

    $studentQuery = $conn->prepare(
        "SELECT students.student_id, users.first_name, users.middle_name, users.last_name
         FROM class_subjects
         JOIN enrollments ON enrollments.section_id = class_subjects.section_id
            AND enrollments.school_year = class_subjects.school_year
            AND enrollments.status = 'Enrolled'
         JOIN students ON students.student_id = enrollments.student_id
         JOIN users ON users.user_id = students.user_id
            AND users.approval_status = 'approved'
         WHERE class_subjects.class_subject_id = ?
         ORDER BY users.last_name, users.first_name"
    );
    $studentQuery->bind_param("i", $classSubjectId);
    $studentQuery->execute();
    $students = $studentQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $studentQuery->close();

    $scoresByAssessment = [];
    $scoreQuery = $conn->prepare(
        "SELECT student_scores.assessment_id, student_scores.student_id, student_scores.score
         FROM student_scores
         JOIN assessments ON assessments.assessment_id = student_scores.assessment_id
         WHERE assessments.class_subject_id = ? AND assessments.grading_period = ?
            AND assessments.activity_status = 'Active'"
    );
    $scoreQuery->bind_param("ii", $classSubjectId, $gradingPeriod);
    $scoreQuery->execute();
    $scoreRows = $scoreQuery->get_result();
    while ($score = $scoreRows->fetch_assoc()) {
        $scoresByAssessment[(int) $score["assessment_id"]][(int) $score["student_id"]] = (float) $score["score"];
    }
    $scoreQuery->close();

    $componentById = [];
    $weightTotal = 0.0;
    foreach ($components as $component) {
        $component["weight_percentage"] = (float) $component["weight_percentage"];
        $componentById[(int) $component["component_id"]] = $component;
        if ($component["weight_percentage"] > 0) {
            $weightTotal += $component["weight_percentage"];
        }
    }
    $resultsByStudent = [];
    foreach ($students as $student) {
        $studentId = (int) $student["student_id"];
        $complete = abs($weightTotal - 100.0) < 0.01 && count($components) > 0;
        $weightedTotal = 0.0;
        $scoredWeightTotal = 0.0;
        $componentResults = [];
        foreach ($components as $component) {
            $componentId = (int) $component["component_id"];
            $weight = (float) $component["weight_percentage"];
            $earned = 0.0;
            $possible = 0.0;
            $activityCount = 0;
            $scoredCount = 0;
            foreach ($assessments as $assessment) {
                if ((int) $assessment["component_id"] !== $componentId) {
                    continue;
                }
                $activityCount++;
                $possible += (float) $assessment["max_score"];
                $assessmentId = (int) $assessment["assessment_id"];
                if (!array_key_exists($studentId, $scoresByAssessment[$assessmentId] ?? [])) {
                    continue;
                }
                $earned += (float) $scoresByAssessment[$assessmentId][$studentId];
                $scoredCount++;
            }
            // A configured component with no assessments contributes zero, so
            // it does not prevent teachers from releasing completed grades.
            $componentComplete = $activityCount === 0 || ($scoredCount === $activityCount && $possible > 0);
            $componentHasScores = ($activityCount === 0 && $weight > 0) || ($scoredCount > 0 && $possible > 0);
            $percentage = $activityCount === 0 && $weight > 0
                ? 0.0
                : ($componentHasScores ? ($earned / $possible) * 100 : null);
            $contribution = $componentHasScores ? $percentage * ($weight / 100) : null;
            if ($weight > 0 && !$componentComplete) {
                $complete = false;
            }
            if ($weight > 0 && $componentHasScores) {
                $weightedTotal += $contribution;
                $scoredWeightTotal += $weight;
            }
            $componentResults[$componentId] = [
                "component_name" => $component["component_name"],
                "weight_percentage" => $weight,
                "earned_points" => $earned,
                "possible_points" => $possible,
                "percentage_score" => $percentage,
                "weighted_contribution" => $contribution,
                "activity_count" => $activityCount,
                "scored_count" => $scoredCount,
                "complete" => $componentComplete
            ];
        }
        $resultsByStudent[$studentId] = [
            "complete" => $complete,
            "initial_grade" => $complete ? $weightedTotal : null,
            "possible_grade" => $scoredWeightTotal > 0 ? ($weightedTotal / $scoredWeightTotal) * 100 : null,
            "components" => $componentResults
        ];
    }
    return [
        "components" => $components,
        "component_by_id" => $componentById,
        "assessments" => $assessments,
        "students" => $students,
        "scores_by_assessment" => $scoresByAssessment,
        "weight_total" => $weightTotal,
        "results_by_student" => $resultsByStudent
    ];
}
