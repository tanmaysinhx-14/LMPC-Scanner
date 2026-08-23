export type ApiEnvelope<T> = {
  status: number;
  message: string;
  data: T;
  timestamp: number;
};

export type User = {
  id: number;
  name: string;
  email: string;
  role: "citizen" | "worker" | "admin" | string;
  ward_id: number | null;
  city: string | null;
};

export type AuthData = {
  access_token: string;
  refresh_token: string;
  token_type: "Bearer" | string;
  expires_in: number;
  expires_at: string;
  refresh_expires_in: number;
  refresh_expires_at: string;
  user: User;
};

export type AiDetection = {
  class: string;
  category: string;
  confidence: number;
  bbox: [number, number, number, number] | number[];
};

export type AiAnalysis = {
  category: string;
  severity: number;
  confidence: number;
  low_confidence: boolean;
  is_manipulated: boolean;
  detections: AiDetection[];
  detection_count: number;
  annotated_image?: string | null;
  model_version?: string;
  image_width?: number;
  image_height?: number;
};

export type AnalyzeData = { ai: AiAnalysis };

export type SubmitData = {
  issue_id: number;
  grouped: boolean;
  category: string;
  ai: AiAnalysis;
};

export type IssueImage = {
  id: number;
  url: string;
  original_name: string | null;
  created_at: string;
};

export type StatusHistory = {
  old_status: string | null;
  new_status: string | null;
  note: string | null;
  created_at: string;
  changed_by_name: string | null;
};

export type IssueAssignment = {
  id: number;
  assigned_at: string;
  completed_at: string | null;
  citizen_verified_at: string | null;
  citizen_reopen_reason: string | null;
  after_image_path: string | null;
  worker_name: string;
  assigned_by_name: string | null;
};

export type IssueDetail = Issue & {
  reporter_name: string | null;
  images: IssueImage[];
  status_history: StatusHistory[];
  assignment: IssueAssignment | null;
  viewer_reported: boolean;
  viewer_upvoted: boolean;
};

export type CitizenDashboardData = {
  stats: { total_reports: number; resolved: number; active: number; upvotes: number };
  rank: number;
  activity: Array<Issue & { citizen_report_count: number; citizen_verified: boolean; last_reported_at: string | null }>;
  community: FeedData;
};

export type WorkerAssignment = {
  assignment_id: number;
  issue_id: number;
  notes: string | null;
  assigned_at: string;
  completed_at: string | null;
  title: string | null;
  description: string | null;
  category: string;
  severity: number;
  status: string;
  address: string | null;
  priority_score: number | string | null;
  created_at: string;
  upvote_count: number;
  report_count: number;
  image_count: number;
  cover_url?: string | null;
};

export type WorkRequest = {
  id: number;
  issue_id: number | null;
  title: string | null;
  message: string;
  status: "pending" | "approved" | "declined" | "cancelled" | string;
  created_at: string;
};

export type WorkerData = {
  assignments: WorkerAssignment[];
  available_issues: Array<{ id: number; title: string | null; category: string; address: string | null; priority_score: number | string; report_count: number }>;
  work_requests: WorkRequest[];
  stats: { active: number; completed: number };
};

export type Issue = {
  id: number;
  title: string | null;
  description: string | null;
  category: string;
  department: string | null;
  severity: number;
  status: string;
  lat: number;
  lng: number;
  address: string | null;
  upvote_count: number;
  report_count: number;
  image_count: number;
  update_count: number;
  ai_confidence: number | null;
  priority_score: number | null;
  priority_band?: "low" | "medium" | "high" | "critical" | string;
  priority_reason?: string | null;
  same_location_report_count?: number;
  nearby_similar_reports?: number;
  nearby_different_reports?: number;
  nearby_similar_issues?: number;
  city_similar_reports?: number;
  city_similar_issues?: number;
  cover_url: string | null;
  is_verified: boolean | number;
  is_recurring: boolean;
  recurrence_of: number | null;
  reporter_name: string | null;
  assigned_worker_name: string | null;
  assigned_by_name: string | null;
  assignment_completed_at: string | null;
  citizen_verified: boolean;
  viewer_upvoted: boolean;
  created_at: string;
};

export type FeedData = {
  items: Issue[];
  pagination: {
    page: number;
    limit: number;
    total: number;
    pages: number;
  };
};

export type HeatmapPoint = {
  id: number;
  lat: number;
  lng: number;
  count: number;
  report_count: number;
  severity: number;
  upvotes: number;
  band: "red" | "yellow" | "green" | string;
  weight: number;
  category: string;
  status: string;
  title: string;
  address: string;
  created_at: string;
};

export type HeatmapData = {
  points: HeatmapPoint[];
  source: "database" | string;
};
