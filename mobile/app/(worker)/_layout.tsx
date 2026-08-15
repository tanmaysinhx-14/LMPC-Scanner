import { ActivityIndicator, StyleSheet, Text, View } from "react-native";
import { Redirect, Tabs } from "expo-router";
import { Ionicons } from "@expo/vector-icons";
import { useAuth } from "../../src/auth/AuthProvider";

export default function WorkerLayout() {
  const { ready, user } = useAuth();
  if (!ready) return <View style={styles.loading}><ActivityIndicator color="#4f46e5" /><Text style={styles.loadingText}>Loading field workspace…</Text></View>;
  if (!user) return <Redirect href="/login" />;
  if (user.role !== "worker") return user.role === "admin" ? <Redirect href="/unsupported" /> : <Redirect href="/(tabs)" />;
  return <Tabs screenOptions={{ headerShown: false, tabBarActiveTintColor: "#4f46e5", tabBarInactiveTintColor: "#8a93a3", tabBarLabelStyle: { fontWeight: "700", fontSize: 11 }, tabBarStyle: { height: 66, paddingTop: 5, paddingBottom: 8, borderTopColor: "#e5e7eb", backgroundColor: "#ffffff" } }}>
    <Tabs.Screen name="index" options={{ title: "Assignments", tabBarIcon: ({ color, size }) => <Ionicons name="briefcase-outline" color={color} size={size} /> }} />
    <Tabs.Screen name="feed" options={{ title: "Feed", tabBarIcon: ({ color, size }) => <Ionicons name="newspaper-outline" color={color} size={size} /> }} />
    <Tabs.Screen name="pulse" options={{ title: "City Pulse", tabBarIcon: ({ color, size }) => <Ionicons name="pulse-outline" color={color} size={size} /> }} />
    <Tabs.Screen name="profile" options={{ title: "Profile", tabBarIcon: ({ color, size }) => <Ionicons name="person-outline" color={color} size={size} /> }} />
  </Tabs>;
}

const styles = StyleSheet.create({ loading: { flex: 1, alignItems: "center", justifyContent: "center", gap: 10, backgroundColor: "#f6f7fb" }, loadingText: { color: "#687386", fontWeight: "700" } });
