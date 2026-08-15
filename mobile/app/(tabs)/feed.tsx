import { useState } from "react";
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from "react-native";
import { useRouter } from "expo-router";
import { useQuery } from "@tanstack/react-query";
import { civicConnectApi } from "../../src/api/client";
import { IssueCard } from "../../src/components/IssueCard";
import { Screen } from "../../src/components/Screen";
import { StatusView } from "../../src/components/StatusView";
import { colors, radii, shadows } from "../../src/theme";

const statuses = ["all", "open", "in_progress", "resolved"];

export default function FeedScreen() {
  const router = useRouter();
  const query = useQuery({ queryKey: ["feed"], queryFn: civicConnectApi.getFeed, refetchInterval: 5000, refetchIntervalInBackground: false });
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  if (query.isLoading) return <Screen><ActivityIndicator size="large" color="#4f46e5" /></Screen>;
  if (query.isError || !query.data) return <Screen><StatusView message={query.error?.message ?? "The backend returned no feed data."} onRetry={() => void query.refetch()} /></Screen>;

  const allItems = query.data.data.items;
  const items = allItems.filter((issue) => {
    const haystack = `${issue.title ?? ""} ${issue.address ?? ""} ${issue.category}`.toLowerCase();
    const matchesSearch = !search.trim() || haystack.includes(search.trim().toLowerCase());
    const matchesStatus = statusFilter === "all" || (statusFilter === "open" ? !["resolved", "rejected"].includes(issue.status) : issue.status === statusFilter);
    return matchesSearch && matchesStatus;
  });
  const open = allItems.filter((issue) => !["resolved", "rejected"].includes(issue.status)).length;
  const inProgress = allItems.filter((issue) => issue.status === "in_progress").length;
  const resolved = allItems.filter((issue) => issue.status === "resolved").length;

  return (
    <Screen refreshing={query.isRefetching} onRefresh={() => void query.refetch()}>
      <View style={styles.heading}><View style={styles.headingRow}><View><Text style={styles.eyebrow}>COMMUNITY FEED</Text><Text style={styles.title}>What residents are reporting</Text></View><View style={styles.livePill}><View style={styles.liveDot} /><Text style={styles.liveText}>Live</Text></View></View><Text style={styles.subtitle}>Grouped issues stay connected to the same status, assignment, evidence, and resolution workflow as the web app.</Text><Text style={styles.priorityNote}>Highest civic priority appears first · refreshes every 5 seconds</Text></View>
      <View style={styles.stats}><View><Text style={styles.statNumber}>{open}</Text><Text style={styles.statLabel}>Open</Text></View><View><Text style={styles.statNumber}>{inProgress}</Text><Text style={styles.statLabel}>In progress</Text></View><View><Text style={[styles.statNumber, styles.resolved]}>{resolved}</Text><Text style={styles.statLabel}>Resolved</Text></View></View>
      <TextInput value={search} onChangeText={setSearch} placeholder="Search places or issues" style={styles.search} />
      <View style={styles.filters}>{statuses.map((status) => <Pressable key={status} onPress={() => setStatusFilter(status)} style={[styles.filter, statusFilter === status && styles.filterActive]}><Text style={[styles.filterText, statusFilter === status && styles.filterTextActive]}>{status === "all" ? "All" : status === "open" ? "Open" : status === "in_progress" ? "In progress" : "Resolved"}</Text></Pressable>)}</View>
      <Text style={styles.resultCount}>{items.length} visible of {query.data.data.pagination.total} issues</Text>
      {items.length ? items.map((issue) => <IssueCard key={issue.id} issue={issue} onPress={() => router.push(`/issue/${issue.id}`)} />) : <Text style={styles.empty}>No issues match these filters.</Text>}
    </Screen>
  );
}

const styles = StyleSheet.create({
  heading: { gap: 7, marginBottom: 2, padding: 17, borderRadius: radii.card, backgroundColor: colors.primaryDark, ...shadows.card },
  headingRow: { flexDirection: "row", alignItems: "flex-start", justifyContent: "space-between", gap: 8 },
  eyebrow: { color: "#aebcfb", fontSize: 11, fontWeight: "900", letterSpacing: 1.1 },
  title: { color: "#fff", fontSize: 25, lineHeight: 30, fontWeight: "900", letterSpacing: -0.6, marginTop: 3 },
  subtitle: { color: "#dbe3ff", lineHeight: 20 },
  priorityNote: { color: "#9fe7dc", fontSize: 11, fontWeight: "800" },
  livePill: { flexDirection: "row", alignItems: "center", gap: 5, paddingVertical: 6, paddingHorizontal: 8, borderRadius: radii.pill, backgroundColor: "rgba(126,224,209,.16)" },
  liveDot: { width: 7, height: 7, borderRadius: 7, backgroundColor: "#7de0d1" },
  liveText: { color: "#b8fff2", fontSize: 11, fontWeight: "900" },
  stats: { flexDirection: "row", justifyContent: "space-around", padding: 14, borderRadius: radii.card, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.line, ...shadows.card },
  statNumber: { color: colors.danger, fontSize: 21, fontWeight: "900", textAlign: "center" },
  resolved: { color: colors.success },
  statLabel: { color: colors.muted, fontSize: 11, fontWeight: "800", textAlign: "center", marginTop: 3 },
  search: { minHeight: 48, borderRadius: radii.control, borderWidth: 1, borderColor: colors.line, backgroundColor: colors.surface, paddingHorizontal: 14, color: colors.ink },
  filters: { flexDirection: "row", flexWrap: "wrap", gap: 8 },
  filter: { paddingVertical: 9, paddingHorizontal: 12, borderRadius: radii.pill, borderWidth: 1, borderColor: colors.line, backgroundColor: colors.surface },
  filterActive: { backgroundColor: colors.primary, borderColor: colors.primary },
  filterText: { color: colors.muted, fontSize: 12, fontWeight: "800" },
  filterTextActive: { color: "#fff" },
  resultCount: { color: colors.muted, fontSize: 12, fontWeight: "800" },
  empty: { padding: 24, borderRadius: radii.card, color: colors.muted, backgroundColor: colors.surface, textAlign: "center" },
});
