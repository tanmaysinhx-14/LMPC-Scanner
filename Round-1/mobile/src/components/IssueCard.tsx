import { Image, Pressable, StyleSheet, Text, View } from "react-native";
import { Ionicons } from "@expo/vector-icons";
import { resolveAssetUrl } from "../config";
import type { Issue } from "../api/types";
import { colors, radii, shadows } from "../theme";

function label(value: string | null | undefined): string {
  return (value ?? "unknown").replace(/_/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusLabel(status: string): string {
  if (status === "pending" || status === "acknowledged") return "Open";
  return label(status);
}

function statusStyle(status: string) {
  if (status === "resolved") return styles.statusResolved;
  if (status === "in_progress") return styles.statusProgress;
  return styles.statusOpen;
}

function priorityColor(band: string | null | undefined): string {
  if (band === "critical") return colors.danger;
  if (band === "high") return "#d97706";
  if (band === "low") return colors.accent;
  return colors.primary;
}

export function IssueCard({ issue, onPress }: { issue: Issue; onPress?: () => void }) {
  const imageUrl = resolveAssetUrl(issue.cover_url);
  const assignment = issue.assigned_worker_name ? `Assigned to ${issue.assigned_worker_name}${issue.assignment_completed_at ? " · Completed" : ""}` : "Awaiting assignment";
  return (
    <Pressable disabled={!onPress} onPress={onPress} style={[styles.card, { borderLeftColor: priorityColor(issue.priority_band) }]}>
      {imageUrl ? <Image source={{ uri: imageUrl }} style={styles.image} /> : null}
      <View style={styles.body}>
        <View style={styles.row}>
          <View style={styles.categoryWrap}><Ionicons name="radio-button-on-outline" size={14} color={colors.primary} /><Text style={styles.category}>{label(issue.category)}</Text></View>
          <Text style={[styles.status, statusStyle(issue.status)]}>{statusLabel(issue.status)}</Text>
        </View>
        <Text style={styles.title}>{issue.title || `${label(issue.category)} reported nearby`}</Text>
        <Text numberOfLines={2} style={styles.address}>{issue.address || "Location recorded"}</Text>
        <View style={styles.workflow}><Text style={styles.workflowText}>{assignment}</Text>{issue.is_recurring ? <Text style={styles.recurring}>Recurring here</Text> : null}</View>
        {issue.city_similar_reports !== undefined ? <Text style={styles.signal}>{issue.nearby_similar_reports ?? 0} similar reports nearby · {issue.city_similar_reports} {label(issue.category).toLowerCase()} reports citywide</Text> : null}
        <View style={styles.metaRow}><Text style={styles.meta}>{issue.report_count} reports · {issue.upvote_count} upvotes</Text><Text style={[styles.priority, { color: priorityColor(issue.priority_band) }]}>Priority {Math.round(Number(issue.priority_score ?? 0))} · {label(issue.priority_band || "medium")}</Text></View>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: { overflow: "hidden", borderRadius: radii.card, backgroundColor: colors.surface, borderWidth: 1, borderLeftWidth: 4, borderColor: colors.line, ...shadows.card },
  image: { width: "100%", height: 180, backgroundColor: "#e8edf5" },
  body: { padding: 16, gap: 8 },
  row: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: 8 },
  categoryWrap: { flexDirection: "row", alignItems: "center", gap: 5, flex: 1 },
  category: { color: colors.primaryDark, fontSize: 12, fontWeight: "900", textTransform: "uppercase", flex: 1 },
  status: { fontSize: 12, fontWeight: "800", paddingVertical: 4, paddingHorizontal: 8, borderRadius: radii.pill, overflow: "hidden" },
  statusOpen: { color: colors.danger, backgroundColor: colors.dangerSoft },
  statusProgress: { color: colors.warning, backgroundColor: colors.warningSoft },
  statusResolved: { color: colors.success, backgroundColor: colors.successSoft },
  title: { color: colors.ink, fontSize: 17, fontWeight: "900", letterSpacing: -0.2 },
  address: { color: colors.muted, lineHeight: 20 },
  workflow: { flexDirection: "row", flexWrap: "wrap", gap: 6, alignItems: "center" },
  workflowText: { color: colors.muted, fontSize: 12, fontWeight: "700" },
  recurring: { color: "#92400e", fontSize: 11, fontWeight: "800", paddingVertical: 3, paddingHorizontal: 7, borderRadius: radii.pill, backgroundColor: "#fef3c7", overflow: "hidden" },
  signal: { color: colors.accentDark, fontSize: 11, lineHeight: 17, fontWeight: "800" },
  metaRow: { flexDirection: "row", justifyContent: "space-between", gap: 8 },
  meta: { color: colors.muted, fontSize: 12 },
  priority: { fontSize: 12, fontWeight: "900" },
});
