import { Linking, Pressable, StyleSheet, Text, View } from "react-native";
import { useAuth } from "../src/auth/AuthProvider";
import { API_BASE_URL } from "../src/config";

export default function UnsupportedRoleScreen() {
  const { user, logout } = useAuth();
  return (
    <View style={styles.safe}>
      <Text style={styles.eyebrow}>WEB PORTAL REQUIRED</Text>
      <Text style={styles.title}>Administrator controls stay on the portal.</Text>
      <Text style={styles.body}>You are signed in as {user?.name || "an administrator"}. Assignment, analytics, and account management remain in the full web workspace so operations staff have the wider desktop view.</Text>
      <Pressable onPress={() => void Linking.openURL(`${API_BASE_URL}/pages/login/`)} style={styles.primary}><Text style={styles.primaryText}>Open web portal</Text></Pressable>
      <Pressable onPress={() => void logout()} style={styles.secondary}><Text style={styles.secondaryText}>Sign out</Text></Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, padding: 24, justifyContent: "center", gap: 14, backgroundColor: "#f6f7fb" },
  eyebrow: { color: "#4f46e5", fontSize: 11, fontWeight: "900", letterSpacing: 1.2 },
  title: { color: "#111827", fontSize: 30, lineHeight: 35, fontWeight: "900" },
  body: { color: "#687386", fontSize: 15, lineHeight: 23 },
  primary: { minHeight: 50, borderRadius: 14, alignItems: "center", justifyContent: "center", backgroundColor: "#4f46e5" },
  primaryText: { color: "#fff", fontWeight: "900" },
  secondary: { minHeight: 50, borderRadius: 14, alignItems: "center", justifyContent: "center", backgroundColor: "#fff", borderWidth: 1, borderColor: "#d9deea" },
  secondaryText: { color: "#374151", fontWeight: "900" },
});
