<?php
/**
 * Quality Assurance System for Maintenance Workflow
 * Professional QA management for maintenance work
 */

/**
 * Create QA checklist for maintenance work
 * 
 * @param int $ticketId
 * @param int $vendorId
 * @param string $category
 * @return array
 */
function create_qa_checklist(int $ticketId, int $vendorId, string $category): array {
    $checklists = [
        'electrical' => [
            ['item' => 'Electrical connections properly secured', 'critical' => true],
            ['item' => 'Wiring concealed and properly routed', 'critical' => true],
            ['item' => 'Switches and outlets function properly', 'critical' => true],
            ['item' => 'Adequate electrical safety clearances', 'critical' => true],
            ['item' => 'Clean up of wire nuts, cable ties, etc.', 'critical' => false],
            ['item' => 'Work area restored to original condition', 'critical' => false],
        ],
        'plumbing' => [
            ['item' => 'All connections are tight and leak-free', 'critical' => true],
            ['item' => 'Water flow and pressure normal', 'critical' => true],
            ['item' => 'Drainage system functioning properly', 'critical' => true],
            ['item' => 'No water damage to surrounding areas', 'critical' => true],
            ['item' => 'Clean up of tools and materials', 'critical' => false],
            ['item' => 'Work area dried and restored', 'critical' => false],
        ],
        'painting' => [
            ['item' => 'Surface properly prepared and primed', 'critical' => true],
            ['item' => 'Paint applied evenly without streaks', 'critical' => true],
            ['item' => 'Edges and corners properly cut in', 'critical' => true],
            ['item' => 'No paint on fixtures or trim', 'critical' => true],
            ['item' => 'Work area protected and clean', 'critical' => false],
            ['item' => 'Furniture and floors protected', 'critical' => false],
        ],
        'cleaning' => [
            ['item' => 'All surfaces thoroughly cleaned', 'critical' => true],
            ['item' => 'No cleaning chemicals residue', 'critical' => true],
            ['item' => 'Waste properly disposed', 'critical' => true],
            ['item' => 'Work area left spotless', 'critical' => true],
            ['item' => 'Equipment cleaned and stored properly', 'critical' => false],
        ],
        'other' => [
            ['item' => 'Work completed according to specifications', 'critical' => true],
            ['item' => 'Quality of materials used appropriate', 'critical' => true],
            ['item' => 'Work area clean and tidy', 'critical' => true],
            ['item' => 'No damage to surrounding property', 'critical' => true],
            ['item' => 'Tools and equipment properly stored', 'critical' => false],
            ['item' => 'Work completed within estimated timeframe', 'critical' => false],
        ]
    ];
    
    $selectedChecklist = $checklists[$category] ?? $checklists['other'];
    
    // Create checklist items in database
    $db = db();
    foreach ($selectedChecklist as $item) {
        $db->insert(
            "INSERT INTO maintenance_qa_checklist 
             (ticket_id, vendor_id, item_description, is_critical, created_at)
             VALUES (?, ?, ?, ?, NOW())",
            [$ticketId, $vendorId, $item['item'], $item['critical']]
        );
    }
    
    return $selectedChecklist;
}

/**
 * Get QA checklist for ticket
 * 
 * @param int $ticketId
 * @return array
 */
function get_qa_checklist(int $ticketId): array {
    try {
        $db = db();
        return $db->fetchAll(
            "SELECT * FROM maintenance_qa_checklist 
             WHERE ticket_id = ? 
             ORDER BY is_critical DESC, id ASC",
            [$ticketId]
        );
    } catch (Throwable $e) {
        error_log('Failed to get QA checklist: ' . $e->getMessage());
        return [];
    }
}

/**
 * Update QA checklist item status
 * 
 * @param int $checklistId
 * @param bool $completed
 * @param string|null $notes
 * @param int|null $completedBy
 * @return bool
 */
function update_qa_checklist_item(int $checklistId, bool $completed, ?string $notes = null, ?int $completedBy = null): bool {
    try {
        $db = db();
        $result = $db->execute(
            "UPDATE maintenance_qa_checklist 
             SET completed = ?, completed_at = ?, completed_by = ?, notes = ?
             WHERE id = ?",
            [$completed, $completed ? 'NOW()' : null, $completedBy, $notes, $checklistId]
        );
        return $result > 0;
    } catch (Throwable $e) {
        error_log('Failed to update QA checklist item: ' . $e->getMessage());
        return false;
    }
}

/**
 * Calculate QA compliance score
 * 
 * @param int $ticketId
 * @return array [score, total_items, completed_items, critical_completed, critical_total]
 */
function calculate_qa_score(int $ticketId): array {
    try {
        $db = db();
        $items = $db->fetchAll(
            "SELECT is_critical, completed FROM maintenance_qa_checklist WHERE ticket_id = ?",
            [$ticketId]
        );
        
        $totalItems = count($items);
        $completedItems = 0;
        $criticalTotal = 0;
        $criticalCompleted = 0;
        
        foreach ($items as $item) {
            if ($item['completed']) {
                $completedItems++;
                if ($item['is_critical']) {
                    $criticalCompleted++;
                }
            }
            if ($item['is_critical']) {
                $criticalTotal++;
            }
        }
        
        $score = $totalItems > 0 ? ($completedItems / $totalItems) * 100 : 0;
        
        return [
            'score' => round($score, 1),
            'total_items' => $totalItems,
            'completed_items' => $completedItems,
            'critical_completed' => $criticalCompleted,
            'critical_total' => $criticalTotal,
            'critical_pass' => $criticalTotal > 0 ? ($criticalCompleted / $criticalTotal) * 100 : 100
        ];
        
    } catch (Throwable $e) {
        error_log('Failed to calculate QA score: ' . $e->getMessage());
        return [
            'score' => 0,
            'total_items' => 0,
            'completed_items' => 0,
            'critical_completed' => 0,
            'critical_total' => 0,
            'critical_pass' => 0
        ];
    }
}

/**
 * Get vendor quality metrics
 * 
 * @param int $vendorId
 * @param int $days
 * @return array
 */
function get_vendor_quality_metrics(int $vendorId, int $days = 30): array {
    try {
        $db = db();
        
        $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $metrics = $db->fetchOne(
            "SELECT 
                COUNT(*) as total_jobs,
                COUNT(CASE WHEN status = 'closed' THEN 1 END) as completed_jobs,
                COUNT(CASE WHEN work_quality_rating >= 4 THEN 1 END) as high_rating_jobs,
                COUNT(CASE WHEN work_quality_rating >= 3 AND work_quality_rating < 4 THEN 1 END) as medium_rating_jobs,
                COUNT(CASE WHEN work_quality_rating < 3 THEN 1 END) as low_rating_jobs,
                COALESCE(AVG(work_quality_rating), 0) as avg_rating,
                COALESCE(AVG(CASE WHEN actual_completion_date IS NOT NULL AND expected_completion_date IS NOT NULL 
                    THEN DATEDIFF(actual_completion_date, expected_completion_date) 
                    ELSE 0 END), 0) as avg_completion_delay
             FROM maintenance_tickets 
             WHERE vendor_id = ? AND created_at >= ?",
            [$vendorId, $startDate]
        );
        
        // Calculate completion rate
        $completionRate = $metrics['total_jobs'] > 0 ? 
            ($metrics['completed_jobs'] / $metrics['total_jobs']) * 100 : 0;
            
        // Calculate quality score
        $qualityScore = $metrics['completed_jobs'] > 0 ? 
            (($metrics['high_rating_jobs'] * 100 + $metrics['medium_rating_jobs'] * 70 + $metrics['low_rating_jobs'] * 30) / $metrics['completed_jobs']) : 0;
        
        return [
            'total_jobs' => (int)$metrics['total_jobs'],
            'completed_jobs' => (int)$metrics['completed_jobs'],
            'completion_rate' => round($completionRate, 1),
            'avg_rating' => round((float)$metrics['avg_rating'], 1),
            'quality_score' => round($qualityScore, 1),
            'high_rating_jobs' => (int)$metrics['high_rating_jobs'],
            'medium_rating_jobs' => (int)$metrics['medium_rating_jobs'],
            'low_rating_jobs' => (int)$metrics['low_rating_jobs'],
            'avg_completion_delay' => round((float)$metrics['avg_completion_delay'], 1),
            'performance_rating' => get_performance_rating($qualityScore, $completionRate)
        ];
        
    } catch (Throwable $e) {
        error_log('Failed to get vendor quality metrics: ' . $e->getMessage());
        return [
            'total_jobs' => 0,
            'completed_jobs' => 0,
            'completion_rate' => 0,
            'avg_rating' => 0,
            'quality_score' => 0,
            'high_rating_jobs' => 0,
            'medium_rating_jobs' => 0,
            'low_rating_jobs' => 0,
            'avg_completion_delay' => 0,
            'performance_rating' => 'Unknown'
        ];
    }
}

/**
 * Get performance rating based on quality and completion metrics
 * 
 * @param float $qualityScore
 * @param float $completionRate
 * @return string
 */
function get_performance_rating(float $qualityScore, float $completionRate): string {
    if ($qualityScore >= 90 && $completionRate >= 95) {
        return 'Excellent';
    } elseif ($qualityScore >= 80 && $completionRate >= 90) {
        return 'Good';
    } elseif ($qualityScore >= 70 && $completionRate >= 80) {
        return 'Satisfactory';
    } elseif ($qualityScore >= 60 && $completionRate >= 70) {
        return 'Needs Improvement';
    } else {
        return 'Poor';
    }
}

/**
 * Create QA report for completed work
 * 
 * @param int $ticketId
 * @return array
 */
function generate_qa_report(int $ticketId): array {
    try {
        $db = db();
        
        // Get ticket details
        $ticket = $db->fetchOne(
            "SELECT mt.*, v.name as vendor_name, v.specialization,
                    t.emergency_contact_name as tenant_name,
                    e.name as estate_name
             FROM maintenance_tickets mt
             LEFT JOIN vendors v ON v.id = mt.vendor_id
             INNER JOIN tenants tn ON tn.id = mt.tenant_id
             INNER JOIN users t ON t.id = tn.user_id
             INNER JOIN estates e ON e.id = mt.estate_id
             WHERE mt.id = ?",
            [$ticketId]
        );
        
        if (!$ticket) {
            return [];
        }
        
        // Get QA checklist
        $checklist = get_qa_checklist($ticketId);
        
        // Calculate scores
        $qaScore = calculate_qa_score($ticketId);
        
        // Get tenant feedback
        $tenantFeedback = $db->fetchOne(
            "SELECT * FROM tenant_confirmations 
             WHERE ticket_id = ? AND tenant_id = ?",
            [$ticketId, (int)$ticket['tenant_id']]
        );
        
        return [
            'ticket' => $ticket,
            'checklist' => $checklist,
            'qa_score' => $qaScore,
            'tenant_feedback' => $tenantFeedback,
            'generated_at' => date('Y-m-d H:i:s'),
            'report_id' => 'QA-' . date('Ymd') . '-' . $ticketId
        ];
        
    } catch (Throwable $e) {
        error_log('Failed to generate QA report: ' . $e->getMessage());
        return [];
    }
}

/**
 * Flag quality issues for review
 * 
 * @param int $ticketId
 * @param string $issueDescription
 * @param int $flaggedBy
 * @return bool
 */
function flag_quality_issue(int $ticketId, string $issueDescription, int $flaggedBy): bool {
    try {
        $db = db();
        $db->insert(
            "INSERT INTO maintenance_quality_issues 
             (ticket_id, issue_description, flagged_by, flagged_at, status)
             VALUES (?, ?, ?, NOW(), 'pending')",
            [$ticketId, $issueDescription, $flaggedBy]
        );
        return true;
    } catch (Throwable $e) {
        error_log('Failed to flag quality issue: ' . $e->getMessage());
        return false;
    }
}