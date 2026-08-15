import { useState } from "react";
import { ActivityIndicator, Alert, Image, Pressable, StyleSheet, Text, TextInput, View } from "react-native";
import { useLocalSearchParams, useRouter } from "expo-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { civicConnectApi } from "../../src/api/client";
import { resolveAssetUrl } from "../../src/config";
import { useAuth } from "../../src/auth/AuthProvider";
import { Screen } from "../../src/components/Screen";
import { StatusView } from "../../src/components/StatusView";

function label(value: string | null | undefined): string { return (value || "unknown").replace(/_/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase()); }
function statusLabel(status: string): string { return status === "pending" || status === "acknowledged" ? "Open" : label(status); }
function statusColor(status: string): string { return status === "resolved" ? "#167044" : status === "in_progress" ? "#925b00" : "#b42318"; }

export default function IssueDetailScreen() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const params = useLocalSearchParams<{ id: string }>();
  const issueId = Number(params.id);
  const [busy, setBusy] = useState(false);
  const [reopenReason, setReopenReason] = useState("");
  const query = useQuery({ queryKey: ["issue", issueId], queryFn: () => civicConnectApi.getIssueDetail(issueId), enabled: Number.isInteger(issueId) && issueId > 0, refetchInterval: 5000, refetchIntervalInBackground: false });

  if (!Number.isInteger(issueId) || issueId < 1) return <Screen><StatusView message="This issue link is not valid." /></Screen>;
  if (query.isLoading) return <Screen><ActivityIndicator size="large" color="#4f46e5" /></Screen>;
  if (query.isError || !query.data) return <Screen><StatusView message={query.error?.message ?? "This issue could not be loaded."} onRetry={() => void query.refetch()} /></Screen>;

  const issue = query.data.data.issue;
  const isCitizen = user?.role === "citizen";
  const canVerify = isCitizen && issue.viewer_reported && issue.status === "resolved" && Boolean(issue.assignment?.completed_at) && !issue.assignment?.citizen_verified_at;

  async function upvote(): Promise<void> {
    setBusy(true);
    try {
      await civicConnectApi.toggleUpvote(issueId);
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ["issue", issueId] }),
        queryClient.invalidateQueries({ queryKey: ["feed"] }),
        queryClient.invalidateQueries({ queryKey: ["citizen-dashboard"] }),
      ]);
    } catch (error) { Alert.alert("Could not update support", error instanceof Error ? error.message : "Please try again."); }
    finally { setBusy(false); }
  }

  async function resolution(action: "verify" | "reopen"): Promise<void> {
    if (action === "reopen" && !reopenReason.trim()) { Alert.alert("Add a reason", "Tell the field team what still needs attention."); return; }
    setBusy(true);
    try {
      await civicConnectApi.verifyResolution(issueId, action, reopenReason.trim());
      setReopenReason("");
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ["issue", issueId] }),
        queryClient.invalidateQueries({ queryKey: ["feed"] }),
        queryClient.invalidateQueries({ queryKey: ["citizen-dashboard"] }),
      ]);
      Alert.alert(action === "verify" ? "Resolution verified" : "Issue reopened", action === "verify" ? "Thank you for closing the loop." : "The issue is back in the work queue.");
    } catch (error) { Alert.alert("Could not update resolution", error instanceof Error ? error.message : "Please try again."); }
    finally { setBusy(false); }
  }

  return (
    <Screen refreshing={query.isRefetching} onRefresh={() => void query.refetch()}>
      <Pressable onPress={() => router.back()}><Text style={styles.back}>‹ Back to feed</Text></Pressable>
      <View style={styles.heading}><View style={styles.headingRow}><Text style={styles.category}>{label(issue.category)}</Text><Text style={[styles.status, { color: statusColor(issue.status) }]}>{statusLabel(issue.status)}</Text></View><Text style={styles.title}>{issue.title || `${label(issue.category)} reported nearby`}</Text><Text style={styles.address}>{issue.address || "Location recorded"}</Text></View>
      {issue.images.map((image) => { const url = resolveAssetUrl(image.url); return url ? <Image key={image.id} source={{ uri: url }} style={styles.image} resizeMode="cover" /> : null; })}
      <View style={styles.card}><Text style={styles.cardTitle}>Resolution status</Text><Text style={styles.workflow}>{issue.assignment?.worker_name ? `Assigned to ${issue.assignment.worker_name}` : "Awaiting administrator assignment"}</Text><Text style={styles.workflow}>{issue.assignment?.completed_at ? `Work completed ${new Date(issue.assignment.completed_at).toLocaleDateString()}` : "Field work is not marked complete yet"}</Text>{issue.assignment?.citizen_verified_at ? <Text style={styles.verified}>✓ You verified this resolution</Text> : null}<Text style={styles.signal}>{issue.nearby_similar_reports ?? 0} similar reports nearby · {issue.city_similar_reports ?? 0} {label(issue.category).toLowerCase()} reports citywide</Text><View style={styles.metrics}><Text style={styles.metric}>{issue.report_count} reports</Text><Text style={styles.metric}>{issue.upvote_count} upvotes</Text><Text style={styles.metric}>Priority {Math.round(Number(issue.priority_score ?? 0))} · {label(issue.priority_band || "medium")}</Text></View></View>
      {issue.description ? <View style={styles.card}><Text style={styles.cardTitle}>Citizen details</Text><Text style={styles.body}>{issue.description}</Text></View> : null}
      {isCitizen ? <View style={styles.card}><Pressable onPress={() => void upvote()} disabled={busy} style={[styles.upvote, issue.viewer_upvoted && styles.upvoteActive]}><Text style={[styles.upvoteText, issue.viewer_upvoted && styles.upvoteTextActive]}>{issue.viewer_upvoted ? "Supported ✓" : "Support this issue"}</Text></Pressable>{issue.viewer_reported ? <Text style={styles.helper}>You reported this grouped issue. We will keep its assignment updates visible here.</Text> : null}</View> : null}
      {canVerify ? <View style={styles.verifyCard}><Text style={styles.verifyTitle}>Did the fix hold up?</Text><Text style={styles.verifyText}>Confirm the worker’s resolution or send it back with a reason. This updates the same status history the administrator sees.</Text><Pressable onPress={() => void resolution("verify")} disabled={busy} style={styles.verifyButton}><Text style={styles.verifyButtonText}>Confirm resolution</Text></Pressable><TextInput value={reopenReason} onChangeText={setReopenReason} placeholder="If it still needs work, explain why" multiline style={styles.reason} /><Pressable onPress={() => void resolution("reopen")} disabled={busy} style={styles.reopenButton}><Text style={styles.reopenText}>Reopen issue</Text></Pressable></View> : null}
      <View style={styles.card}><Text style={styles.cardTitle}>Status history</Text>{issue.status_history.length ? issue.status_history.map((entry, index) => <View key={`${entry.created_at}-${index}`} style={styles.history}><View style={styles.dot} /><View style={styles.historyBody}><Text style={styles.historyTitle}>{statusLabel(entry.new_status || "updated")}</Text><Text style={styles.historyText}>{entry.note || "Status updated"}</Text><Text style={styles.historyDate}>{new Date(entry.created_at).toLocaleString()} {entry.changed_by_name ? `· ${entry.changed_by_name}` : ""}</Text></View></View>) : <Text style={styles.helper}>No status updates have been recorded yet.</Text>}</View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  back: { color: "#4338ca", fontWeight: "900", fontSize: 13 },
  heading: { gap: 7 },
  headingRow: { flexDirection: "row", justifyContent: "space-between", alignItems: "center", gap: 8 },
  category: { color: "#4f46e5", fontSize: 12, fontWeight: "900", textTransform: "uppercase", flex: 1 },
  status: { paddingVertical: 5, paddingHorizontal: 9, borderRadius: 999, backgroundColor: "#fff", borderWidth: 1, borderColor: "#e8eaf0", fontSize: 12, fontWeight: "900", overflow: "hidden" },
  title: { color: "#111827", fontSize: 28, lineHeight: 33, fontWeight: "900", letterSpacing: -0.6 },
  address: { color: "#687386", lineHeight: 20 },
  image: { width: "100%", height: 240, borderRadius: 18, backgroundColor: "#e8eaf0" },
  card: { padding: 16, borderRadius: 18, backgroundColor: "#fff", borderWidth: 1, borderColor: "#e8eaf0", gap: 9 },
  cardTitle: { color: "#111827", fontSize: 16, fontWeight: "900" },
  workflow: { color: "#596273", fontSize: 13, lineHeight: 19 },
  verified: { color: "#166534", fontSize: 13, fontWeight: "900" },
  signal: { color: "#4c6b59", fontSize: 12, lineHeight: 18, fontWeight: "700" },
  metrics: { flexDirection: "row", flexWrap: "wrap", gap: 8, marginTop: 2 },
  metric: { color: "#687386", fontSize: 12, fontWeight: "800", paddingVertical: 6, paddingHorizontal: 9, borderRadius: 999, backgroundColor: "#f6f7fb" },
  body: { color: "#596273", lineHeight: 21 },
  upvote: { minHeight: 46, alignItems: "center", justifyContent: "center", borderRadius: 13, borderWidth: 1, borderColor: "#c7d2fe", backgroundColor: "#eef2ff" },
  upvoteActive: { backgroundColor: "#4f46e5", borderColor: "#4f46e5" },
  upvoteText: { color: "#4338ca", fontWeight: "900" },
  upvoteTextActive: { color: "#fff" },
  helper: { color: "#7a8494", fontSize: 12, lineHeight: 18 },
  verifyCard: { padding: 16, borderRadius: 18, backgroundColor: "#ecfdf5", borderWidth: 1, borderColor: "#bbf7d0", gap: 10 },
  verifyTitle: { color: "#166534", fontSize: 17, fontWeight: "900" },
  verifyText: { color: "#166534", lineHeight: 19 },
  verifyButton: { minHeight: 46, borderRadius: 13, alignItems: "center", justifyContent: "center", backgroundColor: "#16a34a" },
  verifyButtonText: { color: "#fff", fontWeight: "900" },
  reason: { minHeight: 78, padding: 12, borderRadius: 12, borderWidth: 1, borderColor: "#bbf7d0", backgroundColor: "#fff", color: "#111827", textAlignVertical: "top" },
  reopenButton: { minHeight: 44, borderRadius: 13, alignItems: "center", justifyContent: "center", borderWidth: 1, borderColor: "#f59e0b" },
  reopenText: { color: "#92400e", fontWeight: "900" },
  history: { flexDirection: "row", gap: 10 },
  dot: { width: 10, height: 10, marginTop: 5, borderRadius: 99, backgroundColor: "#4f46e5" },
  historyBody: { flex: 1, gap: 2 },
  historyTitle: { color: "#111827", fontWeight: "900" },
  historyText: { color: "#596273", fontSize: 12, lineHeight: 17 },
  historyDate: { color: "#8a93a3", fontSize: 11 },
});
