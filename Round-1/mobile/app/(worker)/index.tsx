import { useState } from "react";
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, TextInput, View } from "react-native";
import { useRouter } from "expo-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { civicConnectApi } from "../../src/api/client";
import { useAuth } from "../../src/auth/AuthProvider";
import { Screen } from "../../src/components/Screen";
import { StatusView } from "../../src/components/StatusView";

function label(value: string | null | undefined): string { return (value || "unknown").replace(/_/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase()); }

export default function WorkerAssignmentsScreen() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const { user, logout } = useAuth();
  const query = useQuery({ queryKey: ["worker-data"], queryFn: civicConnectApi.getWorkerData, refetchInterval: 5000, refetchIntervalInBackground: false });
  const [busyId, setBusyId] = useState<number | null>(null);
  const [requestIssueId, setRequestIssueId] = useState<number | null>(null);
  const [requestMessage, setRequestMessage] = useState("I am available to take this task and inspect it in the field.");

  if (query.isLoading) return <Screen><ActivityIndicator size="large" color="#4f46e5" /></Screen>;
  if (query.isError || !query.data) return <Screen><StatusView message={query.error?.message ?? "The worker workspace could not be loaded."} onRetry={() => void query.refetch()} /></Screen>;
  const data = query.data.data;

  async function updateStatus(issueId: number, status: "in_progress" | "resolved"): Promise<void> {
    setBusyId(issueId);
    try { await civicConnectApi.updateStatus(issueId, status, status === "resolved" ? "Marked completed by field worker." : "Worker started field work."); await queryClient.invalidateQueries({ queryKey: ["worker-data"] }); await queryClient.invalidateQueries({ queryKey: ["feed"] }); await queryClient.invalidateQueries({ queryKey: ["issue", issueId] }); }
    catch (error) { Alert.alert("Status update failed", error instanceof Error ? error.message : "Please try again."); }
    finally { setBusyId(null); }
  }

  async function requestWork(issueId: number): Promise<void> {
    if (!requestMessage.trim()) return;
    setBusyId(issueId);
    try { await civicConnectApi.requestWork(issueId, requestMessage.trim()); setRequestIssueId(null); await queryClient.invalidateQueries({ queryKey: ["worker-data"] }); }
    catch (error) { Alert.alert("Request failed", error instanceof Error ? error.message : "Please try again."); }
    finally { setBusyId(null); }
  }

  return (
    <Screen refreshing={query.isRefetching} onRefresh={() => void query.refetch()}>
      <View style={styles.hero}><View style={styles.heroTop}><View><Text style={styles.eyebrow}>FIELD OPERATIONS</Text><Text style={styles.greeting}>Hi, {user?.name?.split(" ")[0] || "worker"}</Text></View><Pressable onPress={() => void logout()}><Text style={styles.logout}>Sign out</Text></Pressable></View><Text style={styles.title}>Turn the next signal into a fix.</Text><Text style={styles.subtitle}>Every status change is visible to the citizen and administrator through the shared CivicConnect workflow.</Text></View>
      <View style={styles.stats}><View><Text style={styles.statNumber}>{data.stats.active}</Text><Text style={styles.statLabel}>Active tasks</Text></View><View><Text style={[styles.statNumber, styles.done]}>{data.stats.completed}</Text><Text style={styles.statLabel}>Completed</Text></View><View><Text style={styles.statNumber}>{data.available_issues.length}</Text><Text style={styles.statLabel}>Available</Text></View></View>
      <View style={styles.section}><View style={styles.sectionHeader}><View><Text style={styles.sectionEyebrow}>MY QUEUE</Text><Text style={styles.sectionTitle}>Assigned to me</Text></View><Text style={styles.count}>{data.assignments.length} total</Text></View>{data.assignments.length ? data.assignments.map((assignment) => { const done = Boolean(assignment.completed_at); const next = assignment.status === "in_progress" ? "resolved" : "in_progress"; return <View key={assignment.assignment_id} style={[styles.assignment, done && styles.assignmentDone]}><Pressable onPress={() => router.push(`/issue/${assignment.issue_id}`)} style={styles.assignmentMain}><View style={styles.row}><Text style={styles.category}>{label(assignment.category)}</Text><Text style={[styles.status, done ? styles.statusDone : assignment.status === "in_progress" ? styles.statusProgress : styles.statusOpen]}>{done ? "Completed" : label(assignment.status)}</Text></View><Text style={styles.assignmentTitle}>{assignment.title || `${label(assignment.category)} issue`}</Text><Text style={styles.address}>{assignment.address || "Location recorded"}</Text><Text style={styles.meta}>{assignment.report_count} reports · {assignment.image_count} photos · priority {Math.round(Number(assignment.priority_score || 0))}</Text>{assignment.notes ? <Text style={styles.note}>Instruction: {assignment.notes}</Text> : null}</Pressable>{!done ? <Pressable disabled={busyId === assignment.issue_id} onPress={() => void updateStatus(assignment.issue_id, next)} style={[styles.action, next === "resolved" && styles.completeAction]}><Text style={styles.actionText}>{busyId === assignment.issue_id ? "Saving…" : next === "resolved" ? "Mark resolved" : "Start work"}</Text></Pressable> : null}</View>; }) : <Text style={styles.empty}>No assignments yet. Request an available issue below or wait for an administrator to assign work.</Text>}</View>
      <View style={styles.section}><View style={styles.sectionHeader}><View><Text style={styles.sectionEyebrow}>REQUEST WORK</Text><Text style={styles.sectionTitle}>Unassigned city issues</Text></View><Text style={styles.count}>{data.available_issues.length} open</Text></View>{data.available_issues.slice(0, 8).map((issue) => <View key={issue.id} style={styles.available}><Pressable onPress={() => router.push(`/issue/${issue.id}`)} style={styles.availableMain}><Text style={styles.category}>{label(issue.category)}</Text><Text style={styles.availableTitle}>{issue.title || `${label(issue.category)} issue`}</Text><Text style={styles.address}>{issue.address || "Location recorded"} · {issue.report_count} reports</Text></Pressable><Pressable onPress={() => setRequestIssueId(requestIssueId === issue.id ? null : issue.id)} style={styles.requestButton}><Text style={styles.requestButtonText}>{requestIssueId === issue.id ? "Close" : "Request"}</Text></Pressable>{requestIssueId === issue.id ? <View style={styles.requestBox}><TextInput value={requestMessage} onChangeText={setRequestMessage} multiline style={styles.requestInput} /><Pressable disabled={busyId === issue.id} onPress={() => void requestWork(issue.id)} style={styles.sendButton}><Text style={styles.sendText}>{busyId === issue.id ? "Sending…" : "Send request"}</Text></Pressable></View> : null}</View>)}{!data.available_issues.length ? <Text style={styles.empty}>Every active issue currently has an assignment or there are no open issues available.</Text> : null}</View>
      <View style={styles.section}><View style={styles.sectionHeader}><View><Text style={styles.sectionEyebrow}>REQUEST HISTORY</Text><Text style={styles.sectionTitle}>Administrator responses</Text></View></View>{data.work_requests.length ? data.work_requests.map((request) => <View key={request.id} style={styles.requestRow}><View style={{ flex: 1 }}><Text style={styles.availableTitle}>{request.title || "Work request"}</Text><Text style={styles.meta}>{request.message}</Text></View><Text style={[styles.requestStatus, request.status === "approved" ? styles.approved : request.status === "declined" ? styles.declined : styles.pending]}>{label(request.status)}</Text></View>) : <Text style={styles.empty}>Your work requests will appear here.</Text>}</View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  hero: { padding: 22, borderRadius: 24, backgroundColor: "#312e81", gap: 11 },
  heroTop: { flexDirection: "row", justifyContent: "space-between", alignItems: "flex-start" },
  eyebrow: { color: "#c7d2fe", fontSize: 11, fontWeight: "900", letterSpacing: 1.2 },
  greeting: { color: "#fff", fontSize: 16, fontWeight: "900", marginTop: 4 },
  logout: { color: "#c7d2fe", fontSize: 12, fontWeight: "800" },
  title: { color: "#fff", fontSize: 28, lineHeight: 34, fontWeight: "900", letterSpacing: -0.6 },
  subtitle: { color: "#e0e7ff", lineHeight: 21 },
  stats: { flexDirection: "row", justifyContent: "space-around", padding: 16, borderRadius: 16, backgroundColor: "#fff", borderWidth: 1, borderColor: "#e8eaf0" },
  statNumber: { color: "#b42318", fontSize: 23, fontWeight: "900", textAlign: "center" },
  done: { color: "#167044" },
  statLabel: { color: "#687386", fontSize: 11, fontWeight: "700", textAlign: "center", marginTop: 3 },
  section: { gap: 11 },
  sectionHeader: { flexDirection: "row", justifyContent: "space-between", alignItems: "flex-end", gap: 8 },
  sectionEyebrow: { color: "#4f46e5", fontSize: 10, fontWeight: "900", letterSpacing: 1 },
  sectionTitle: { color: "#111827", fontSize: 19, fontWeight: "900", marginTop: 3 },
  count: { color: "#7a8494", fontSize: 12, fontWeight: "800" },
  assignment: { padding: 16, borderRadius: 18, backgroundColor: "#fff", borderWidth: 1, borderColor: "#e8eaf0", gap: 10 },
  assignmentDone: { backgroundColor: "#f7fcf8" },
  assignmentMain: { gap: 7 },
  row: { flexDirection: "row", justifyContent: "space-between", alignItems: "center", gap: 8 },
  category: { color: "#4438ca", fontSize: 11, fontWeight: "900", textTransform: "uppercase", flex: 1 },
  status: { paddingVertical: 4, paddingHorizontal: 8, borderRadius: 999, fontSize: 11, fontWeight: "900", overflow: "hidden" },
  statusOpen: { color: "#b42318", backgroundColor: "#fff0ee" },
  statusProgress: { color: "#925b00", backgroundColor: "#fff7df" },
  statusDone: { color: "#167044", backgroundColor: "#eaf8ef" },
  assignmentTitle: { color: "#111827", fontSize: 17, fontWeight: "900" },
  availableTitle: { color: "#111827", fontSize: 15, fontWeight: "900" },
  address: { color: "#596273", fontSize: 13, lineHeight: 18 },
  meta: { color: "#7a8494", fontSize: 11, lineHeight: 17 },
  note: { color: "#596273", padding: 10, borderRadius: 10, backgroundColor: "#f6f7fb", fontSize: 12, lineHeight: 17 },
  action: { minHeight: 44, borderRadius: 12, alignItems: "center", justifyContent: "center", backgroundColor: "#4f46e5" },
  completeAction: { backgroundColor: "#16a34a" },
  actionText: { color: "#fff", fontWeight: "900" },
  available: { padding: 14, borderRadius: 16, backgroundColor: "#fff", borderWidth: 1, borderColor: "#e8eaf0", gap: 9 },
  availableMain: { gap: 5 },
  requestButton: { alignSelf: "flex-start", paddingVertical: 8, paddingHorizontal: 11, borderRadius: 10, backgroundColor: "#eef2ff" },
  requestButtonText: { color: "#4338ca", fontSize: 12, fontWeight: "900" },
  requestBox: { gap: 8, paddingTop: 2 },
  requestInput: { minHeight: 70, padding: 10, borderRadius: 10, borderWidth: 1, borderColor: "#d9deea", color: "#111827", backgroundColor: "#fff", textAlignVertical: "top", fontSize: 12 },
  sendButton: { minHeight: 42, borderRadius: 11, alignItems: "center", justifyContent: "center", backgroundColor: "#4f46e5" },
  sendText: { color: "#fff", fontWeight: "900", fontSize: 12 },
  requestRow: { flexDirection: "row", alignItems: "center", gap: 10, padding: 14, borderRadius: 15, backgroundColor: "#fff", borderWidth: 1, borderColor: "#e8eaf0" },
  requestStatus: { paddingVertical: 5, paddingHorizontal: 8, borderRadius: 999, fontSize: 11, fontWeight: "900", overflow: "hidden" },
  approved: { color: "#166534", backgroundColor: "#dcfce7" },
  declined: { color: "#6b7280", backgroundColor: "#f3f4f6" },
  pending: { color: "#92400e", backgroundColor: "#fef3c7" },
  empty: { padding: 20, borderRadius: 16, color: "#687386", backgroundColor: "#fff", textAlign: "center", lineHeight: 19 },
});
